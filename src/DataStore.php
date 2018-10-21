<?php

declare(strict_types=1);

namespace Resque;

use Predis\Client;
use Resque\Dispatchers\Noop;
use Resque\Interfaces\Dispatcher;
use Resque\Tasks\BeforeJobPop;
use Resque\Tasks\BeforeJobPush;

class DataStore
{
    public const REDIS_DATE_FORMAT = 'Y-m-d H:i:s O';

    private $redis;
    private $dispatcher;

    public function __construct(Client $client)
    {
        $this->redis = $client;
        $this->dispatcher = new Noop();
    }

    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
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

    public function redisKeyForQueue(string $queueName): string
    {
        return "queue:{$queueName}";
    }

    public function pushToFailedQueue(string $json): void
    {
        $this->pushToQueue('failed', $json);
    }

    public function registerWorker(string $workerId): void
    {
        $this->redis->sadd("workers", $workerId);
        $this->workerStarted($workerId);
    }

    public function unregisterWorker(string $workerId): void
    {
        $this->redis->srem("workers", $workerId);
        $this->redis->del($this->redisKeyForWorker($workerId));
        $this->redis->del($this->redisKeyForWorkerStartTime($workerId));
    }

    public function workerStarted(string $workerId): void
    {
        $startTime = (new \DateTime())->format(self::REDIS_DATE_FORMAT);
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

    public function workerDoneWorking(string $workerId): void
    {
        $this->redis->del($this->redisKeyForWorker($workerId));
    }

    public function reconnect(): void
    {
        $this->redis->disconnect();
        $this->redis->connect();
    }
}
