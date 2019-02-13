<?php

declare(strict_types=1);

namespace Resque;

use Psr\Container\ContainerInterface;
use Resque\Dispatchers\Noop;
use Resque\Exceptions\PayloadCorrupt;
use Resque\Interfaces\Dispatcher;
use Resque\Tasks\AfterUserJobPerform;
use Resque\Tasks\BeforeUserJobPerform;
use Resque\Tasks\FailedUserJobPerform;

class Job
{
    private $queueName;
    private $payload;
    private $serviceLocator;
    private $dispatcher;
    private $failed = false;

    public function __construct(string $queueName, array $payload, ContainerInterface $serviceLocator)
    {
        $this->queueName = $queueName;
        $this->payload = $payload;
        $this->serviceLocator = $serviceLocator;
        $this->dispatcher = new Noop();
    }

    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    public function getPayloadClassName(): string
    {
        if (empty($this->payload['class'])) {
            throw new PayloadCorrupt();
        }
        return $this->payload['class'];
    }

    public function getPayloadArguments(): array
    {
        if (empty($this->payload['args'])) {
            throw new PayloadCorrupt();
        }
        return $this->payload['args'];
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function perform(): void
    {
        $this->dispatcher->dispatch(BeforeUserJobPerform::class, $this->payload);
        try {
            $userJob = $this->serviceLocator->get($this->getPayloadClassName());
            $userJob->perform($this->getPayloadArguments());
            $this->dispatcher->dispatch(AfterUserJobPerform::class, $this->payload);
        } catch (\Exception $e) {
            $this->handleFailedJob();
        } catch (\Error $e) {
            $this->handleBrokenJob();
        }
    }

    private function handleFailedJob()
    {
        $this->failed = true;
        $this->dispatcher->dispatch(FailedUserJobPerform::class, $this->payload);
    }

    private function handleBrokenJob()
    {
        $this->failed = true;
        $this->dispatcher->dispatch(BrokenUserJobPerform::class, $this->payload);
    }
}
