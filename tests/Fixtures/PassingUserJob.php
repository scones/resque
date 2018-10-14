<?php

namespace Resque\Tests\Fixtures;

use Resque\Interfaces\JobInterface;

class PassingUserJob implements JobInterface
{
    public function perform(array $arguments): void
    {
    }
}
