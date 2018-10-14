<?php

declare(strict_types=1);

namespace Resque;

use Resque\Dispatchers\Noop;
use Resque\Exceptions\JobClassMissing;
use Resque\Exceptions\QueueMissing;
use Resque\Interfaces\DispatcherInterface;
use Resque\Interfaces\SerializerInterface;
use Resque\Tasks\AfterEnqueue;
use Resque\Tasks\BeforeEnqueue;

class Resque
{
    private $dispatcher;
    private $serializer;
    private $datastore;

    public function __construct(SerializerInterface $serializer, Datastore $datastore)
    {
        $this->serializer = $serializer;
        $this->datastore = $datastore;
        $this->dispatcher = new Noop();
    }

    public function setDispatcher(DispatcherInterface $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    public function enqueue(string $className, array $arguments, string $queueName = ''): void
    {
        $payload = ['class' => $className, 'args' => $arguments];
        $this->validateEnqueue($className, $queueName);

        $payload = $this->dispatcher->dispatch(BeforeEnqueue::class, $payload);
        $this->push($queueName, $payload);
        $this->dispatcher->dispatch(AfterEnqueue::class, $payload);
    }

    private function validateEnqueue(string $className, string $queueName): void
    {
        if (empty($queueName)) {
            throw new QueueMissing();
        }

        if (empty($className)) {
            throw new JobClassMissing();
        }
    }

    private function push(string $queueName, array $payload): void
    {
        $this->datastore->pushToQueue($queueName, $this->serializer->serialize($payload));
    }
}
