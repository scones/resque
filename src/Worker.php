<?php

declare(strict_types=1);

namespace Resque;

use Psr\Container\ContainerInterface;
use Resque\Dispatchers\Noop;
use Resque\Interfaces\Dispatcher;
use Resque\Interfaces\Serializer;
use Resque\Tasks\WorkerDoneWorking;
use Resque\Tasks\WorkerRegistering;
use Resque\Tasks\WorkerStartup;
use Resque\Tasks\WorkerUnregistering;

class Worker
{
    private const FORK_FAILED = -1;
    private const FORK_CHILD = 0;

    private $datastore;
    private $serializer;
    private $queueNames = ['default'];
    private $interval = 10;
    private $shouldShutdown = false;
    private $isPaused = false;
    private $isChildThread = false;
    private $childId = 0;
    private $serviceLocator;
    private $dispatcher = null;

    public function __construct(
        Datastore $datastore,
        Serializer $serializer,
        ContainerInterface $serviceLocator,
        SignalHandler $signalHandler
    ) {
        $this->datastore = $datastore;
        $this->serializer = $serializer;
        $this->serviceLocator = $serviceLocator;
        $this->signalHandler = $signalHandler;
        $this->logger = new NoopLogger();
        $this->dispatcher = new Noop();

        $this->id = gethostname() . '-' . getmypid() . md5(random_bytes(2));
    }

    public function setInterval(int $interval): void
    {
        $this->interval = $interval;
    }

    public function setQueueNames(array $queueNames): void
    {
        $this->queueNames = $queueNames;
    }

    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    public function work(): void
    {
        $this->startup();

        do {
            if ($this->isPaused || !$this->workOneJob()) {
                sleep($this->interval);
            }
        } while (0 < $this->interval && !$this->shouldShutdown);

        $this->unregisterWorker();
    }

    private function startup(): void
    {
        $this->signalHandler->setWorker($this);
        $this->signalHandler->register();
        $this->dispatcher->dispatch(WorkerStartup::class, ['worker' => $this]);
        $this->registerWorker();
    }

    private function registerWorker(): void
    {
        $this->dispatcher->dispatch(WorkerRegistering::class, ['worker' => $this]);
        $this->datastore->registerWorker($this->id);
    }

    private function unregisterWorker(): void
    {
        $this->dispatcher->dispatch(WorkerUnregistering::class, ['worker' => $this]);
        $this->datastore->unregisterWorker($this->id);
    }

    private function workOneJob(): bool
    {
        $job = $this->fetchJob();
        if (empty($job)) {
            return false;
        }

        $this->setWorkingOn($job);
        $this->performWithFork($job);

        return true;
    }

    private function fetchJob(): ?Job
    {
        foreach ($this->queueNames as $queueName) {
            $payload = $this->datastore->popFromQueue($queueName);
            if (!empty($payload)) {
                $this->logger->info('found one job');
                $this->logger->debug("payload: {$payload}");
                return $this->createJobFromPayload($queueName, $payload);
            }
        }
        return null;
    }

    private function setWorkingOn(Job $job): void
    {
        $time = new \DateTime();
        $time->setTimezone(new \DateTimeZone('UTC'));
        $timeString = $time->format(\DateTime::ISO8601);
        $workerPayload = $this->serializer->serialize([
            'queue' => $job->getQueueName(),
            'run_at' => $timeString,
            'payload' => $job->getPayload(),
        ]);
        $this->datastore->setWorkerPayload($this->id, $workerPayload);
    }

    private function performWithFork(Job $job): void
    {
        $this->childId = pcntl_fork();

        switch ($this->childId) {

            case self::FORK_FAILED:
                $this->criticalWorkerShutdown($job);
                break;

            case self::FORK_CHILD:
                $this->performJob($job);
                $this->shouldShutdown = true;
                $this->isChildThread = true;
                break;

            default: // parent case
                $this->waitForChild($job);
        }

        $this->childId = null;
        $this->doneWorking();
    }

    private function doneWorking(): void
    {
        if (!$this->isChildThread) {
            $this->dispatcher->dispatch(WorkerDoneWorking::class, ['worker' => $this]);
        }
    }

    private function createJobFromPayload(string $queueName, string $serializedPayload): Job
    {
        $payload = $this->serializer->unserialize($serializedPayload);
        $factory = $this->serviceLocator->get(Job::class);
        return $factory($queueName, $payload, $this->serviceLocator);
    }

    public function criticalWorkerShutdown(Job $job): void
    {
        $this->requeueJob($job);
        $this->shutdown();
    }

    private function requeueJob(Job $job): void
    {
        $payload = $this->createPayloadFromJob($job);
        $this->datastore->pushToQueue($job->getQueueName(), $payload);
    }

    private function createPayloadFromJob($job): string
    {
        return $this->serializer->serialize($job->getPayload());
    }

    private function performJob(Job $job): void
    {
        $this->datastore->reconnect();
        $job->perform();
    }

    private function waitForChild(Job $job): void
    {
        $status = null;
        pcntl_wait($status);
        if (!pcntl_wifexited($status) || ($exitStatus = pcntl_wexitstatus($status)) !== 0) {
            if ($job->hasFailed()) {
                // user land job has failed. nothing to be done about that ... except maybe queue in failed queue
            } else {
                // unexpected failure - handle dirty exit
            }
        }
    }

    public function shutdown(): void
    {
        $this->shouldShutdown = true;
    }

    public function forceShutdown(): void
    {
        $this->shouldShutdown = true;
        if (!empty($this->childId)) {
            posix_kill($this->childId, SIGTERM) || posix_kill($this->childId, SIGKILL);
        }
    }

    public function pause(): void
    {
        $this->isPaused = true;
    }

    public function continue()
    {
        $this->isPaused = false;
    }
}
