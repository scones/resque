<?php

namespace Resque\Tests\Fixtures;

use Resque\Interfaces\Job;

class FailingUserJob implements Job
{
    public function perform(array $arguments): void
    {
        throw new \Exception('failing');
    }
}
