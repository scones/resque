<?php

namespace Resque\Interfaces;

interface DispatcherInterface
{
    public function dispatch(string $className, array $payload): array;
}
