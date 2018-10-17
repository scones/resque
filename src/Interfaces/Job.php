<?php

declare(strict_types=1);

namespace Resque\Interfaces;

interface Job
{
    public function perform(array $arguments): void;
}
