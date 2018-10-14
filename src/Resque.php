<?php

declare(strict_types=1);

use Psr\EventDispatcher\TaskProcessorInterface;
use Resque\Interfaces\SerializerInterface;

class Resque
{
    private $dispatcher;
    private $serializer;

    public function __construct(SerializerInterface $serializer)
    {
        $this->dispatcher = null;
        $this->serializer = $serializer;
    }

    public function setDispatcher(TaskProcessorInterface $processor): void
    {
        $this->processor = $processor;
    }

    public function enqueue(string $className, array $arguments, string $queueName = ''): void
    {
        $payload = ['class' => $className, 'args' => $arguments];
        $payload = $this->dispatcher->dispatch(BeforeEnqueueTask::class, $payload);

        $this->validateEnqueue($className, $queueName);
        $this->push($queueName, $payload);

        $this->dispatcher->dispatch(AfterEnqueueTask::class, $payload);
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

    private function push(strng $queueName, array $payload): void
    {
        $this->dataStore->pushToQueue($queueName, $this->serializer->serialize($payload));
    }
}
