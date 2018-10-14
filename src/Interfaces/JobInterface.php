<?php

declare(strict_types=1);

namespace Resque\Interfaces;

interface JobInterface
{
    public function perform(array $arguments): void;
}
