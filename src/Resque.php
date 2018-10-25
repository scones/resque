<?php

declare(strict_types=1);

namespace Resque;

use Resque\Dispatchers\Noop;
use Resque\Exceptions\JobClassMissing;
use Resque\Exceptions\QueueMissing;
use Resque\Interfaces\Dispatcher;
use Resque\Interfaces\Serializer;
use Resque\Tasks\AfterEnqueue;
use Resque\Tasks\BeforeEnqueue;

class Resque
{
    private $dispatcher;
    private $serializer;
    private $datastore;

    public function __construct(Datastore $datastore, Serializer $serializer)
    {
        $this->serializer = $serializer;
        $this->datastore = $datastore;
        $this->dispatcher = new Noop();
    }

    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    public function enqueue(string $className, array $arguments, string $queueName = ''): void
    {
        $payload = ['class' => $className, 'args' => $arguments, 'queue_name' => $queueName];
        $this->validateEnqueue($className, $queueName);

        $payload = $this->dispatcher->dispatch(BeforeEnqueue::class, $payload);
        if (empty($payload['skip_queue'])) {
            $this->push($payload['queue_name'], $payload);
        }
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
