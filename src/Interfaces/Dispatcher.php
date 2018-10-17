<?php

namespace Resque\Interfaces;

interface Dispatcher
{
    public function dispatch(string $className, array $payload): array;
}
