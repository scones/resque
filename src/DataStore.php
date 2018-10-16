<?php

declare(strict_types=1);

namespace Resque;

use Predis\Client;
use Resque\Dispatchers\Noop;
use Resque\Interfaces\DispatcherInterface;
use Resque\Tasks\BeforeJobPop;
use Resque\Tasks\BeforeJobPush;

class DataStore
{
    public const REDIS_DATE_FORMAT = 'Y-m-d H:i:s O';
    public const REDIS_KEY_FOR_WORKER_PRUNING = "pruning_dead_workers_in_progress";
    public const REDIS_HEARTBEAT_KEY = "workers:heartbeat";

    private $redis;
    private $namespace;
    private $dispatcher;

    public function __construct(Client $client, string $resqueNamespace)
    {
        $this->redis = $client;
        $this->namespace = $resqueNamespace;
        $this->dispatcher = new Noop();
    }

    public function setDispatcher(DispatcherInterface $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    public function allResqueKeys(): array
    {
        return array_map(
            $this->redis->keys('*'),
            function ($key) {
                return str_replace("{$this->namespace}:", '');
            }
        );
    }

    public function pushToQueue(string $queueName, string $json): void
    {
        $queueKey = $this->redisKeyForQueue($queueName);
        $payload = [
            'queue_key' => $queueKey,
            'queue_name' => $queueName,
            'json' => $json,
            'command' => 'rpush',
        ];
        $payload = $this->dispatcher->dispatch(BeforeJobPush::class, $payload);
        $command = $payload['command'];
        $this->redis->sadd('queues', $payload['queue_name']);
        $this->redis->$command($payload['queue_key'], $payload['json']);
    }

    public function popFromQueue(string $queueName): string
    {
        $payload = ['command' => 'lpop', 'queue_name' => $queueName];
        $payload = $this->dispatcher->dispatch(BeforeJobPop::class, $payload);
        $command = $payload['command'];
        return $this->redis->$command($this->redisKeyForQueue($payload['queue_name']));
    }

    public function queueSize(string $queueName): int
    {
        return intval($this->redis->llen($this->redisKeyForQueue($queueName)));
    }

    public function redisKeyForQueue(string $queueName): string
    {
        return "queue:{$queueName}";
    }

    public function pushToFailedQueue(string $json): void
    {
        $this->pushToQueue($json, 'failed');
    }

    public function getWorkerPayload(string $workerId): string
    {
        return $this->redis->get($this->redisKeyForWorker($workerId));
    }

    public function workerExists(string $workerId): bool
    {
        return $this->redis->sismember("workers", $workerId);
    }

    public function registerWorker(string $workerId): void
    {
        $redis = $this->redis;
        $that = $this;
        $this->redis->pipeline(function () use ($redis, $workerId, $that) {
            $redis->sadd("workers", $workerId);
            $that->workerStarted($workerId);
        });
    }

    public function unregisterWorker(string $workerId): void
    {
        $redis = $this->redis;
        $that = $this;
        $redis->pipeline(function () use ($redis, $workerId, $that) {
            $redis->srem("workers", $workerId);
            $redis->del($that->redisKeyForWorker($workerId));
            $redis->del($that->redisKeyForWorkerStartTime($workerId));
            $that->removeWorkerHeartbeat($workerId);
        });
    }

    public function removeWorkerHeartbeat(string $workerId): void
    {
        $redis->hdel(DataStore::HEARTBEAT_KEY, $workerId);
    }

    public function hasWorkerHeartbeat(string $workerId): bool
    {
        $heartbeat = $this->redis->hget(DataStore::HEARTBEAT_KEY, $workerId);
        return !empty($heartbeat) && DateTime::createFromFormat(DataStore::REDIS_DATE_FORMAT, $heartbeat);
    }

    public function getWorkerHeartbeat(string $workerId): DateTime
    {
        $heartbeat = $this->redis->hget(DataStore::HEARTBEAT_KEY, $workerId);
        return $this->extractHeartbeatDateTime($heartbeat);
    }

    public function getWorkerHeartbeats(): array
    {
        $heartbeats = $this->redis->hgetall(DataStore::HEARTBEAT_KEY);
        return array_map($heartbeats, [$this, 'extractHeartbeatDateTime']);
    }

    public function extractHeartbeatDateTime($heartbeat): DateTime
    {
        if (!empty($heartbeat)) {
            if (false !== ($time = DateTime::createFromFormat(DataStore::REDIS_DATE_FORMAT, $heartbeat))) {
                return $time;
            }
        }
        throw new RuntimeException("Invalid Worker Heartbeat");
    }

    public function acquirePruningDeadWorkerLock(string $workerId, int $expiry): void
    {
        $this->redis->set(self::REDIS_KEY_FOR_WORKER_PRUNING, 'EX', $expiry, 'NX');
    }

    public function workerStarted(string $workerId): void
    {
        $startTime = (new DateTime())->format(self::REDIS_DATE_FORMAT);
        $this->redis->set($this->redisKeyForWorkerStartTime($workerId), $startTime);
    }

    public function redisKeyForWorker(string $workerId): string
    {
        return "worker:{$workerId}";
    }

    public function redisKeyForWorkerStartTime(string $workerId): string
    {
        return "{$this->redisKeyForWorker($workerId)}:started";
    }

    public function setWorkerPayload(string $workerId, string $data): void
    {
        $this->redis->set($this->redisKeyForWorker($workerId), $data);
    }

    public function getWorkerStartTime(string $workerId): DateTime
    {
        $workerStartRedisTime = $this->redis->get($this->redisKeyForWorkerStartTime($workerId));
        if (!empty($workerStartRedisTime)) {
            if (false !== ($workerStartTime = DateTime::createFromFormat(DataStore::REDIS_DATE_FORMAT, $workerStartRedisTime))) {
                return $workerStartTime;
            }
        }
        throw new RuntimeException("Invalid Worker Start Time");
    }

    public function workerDoneWorking(string $workerId, callable $block): void
    {
        $redis = $this->redis;
        $workerKey = $this->redisKeyForWorker($workerId);
        $this->redis->pipeline(function () use ($redis, $workerKey, $block) {
            $redis->del($workerKey);
            $block();
        });
    }

    public function getStat(string $statName): int
    {
        return $this->redis($this->redisKeyForStats($statName));
    }

    public function incrementStat(string $statName, int $by): void
    {
        $this->redis->incrby($this->redisKeyForStats($statName), $by);
    }

    public function decrementStat(string $statName, int $by): void
    {
        $this->redis->decrby($this->redisKeyForStats($statName), $by);
    }

    public function crearStat(string $statName): void
    {
        $this->redis->del($this->redisKeyForStats($statName));
    }

    public function redisKeyForStats(string $statName): string
    {
        return "stats:{$statName}";
    }

    public function reconnect(): void
    {
        $this->redis->disconnect();
        $this->redis->connect();
    }
}
