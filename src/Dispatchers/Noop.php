<?php

namespace Resque\Dispatchers;

use Resque\Interfaces\Dispatcher;

class Noop implements Dispatcher
{
    public function dispatch(string $className, array $payload): array
    {
        return $payload;
    }
}
