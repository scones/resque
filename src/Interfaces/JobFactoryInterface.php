<?php

declare(strict_types=1);

namespace Resque\Interfaces;

interface JobFactoryInterface
{
    public function build(string $queueName, array $payload): Job;
}
