<?php

namespace Resque\Dispatchers;

use Resque\Interfaces\DispatcherInterface;

class Noop implements DispatcherInterface
{
    public function dispatch(string $className, array $payload): array
    {
        return $payload;
    }
}
