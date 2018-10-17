<?php

namespace Resque\Tests\Fixtures;

use Resque\Interfaces\Job;

class PassingUserJob implements Job
{
    public function perform(array $arguments): void
    {
    }
}
