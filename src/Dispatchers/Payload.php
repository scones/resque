<?php

declare(strict_types=1);

namespace Resque\Dispatchers;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\TaskProcessorInterface;
use Resque\Interfaces\DispatcherInterface;

class Payload implements DispatcherInterface
{
    private $taskProcessor;
    private $serviceLocator;

    public function __construct(ContainerInterface $serviceLocator, TaskProcessorInterface $taskProcessor)
    {
        $this->serviceLocator = $serviceLocator;
        $this->taskProcessor = $taskProcessor;
    }

    public function dispatch(string $className, array $payload): array
    {
        $task = $this->serviceLocator->get($className);
        $task->setPayload($payload);
        $task = $this->taskProcessor->process($task);

        /** @scrutinizer ignore-call */
        return $task->getPayload();
    }
}
