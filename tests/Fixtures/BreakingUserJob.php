<?php

namespace Resque\Tests\Fixtures;

use Resque\Interfaces\Job;

class BreakingUserJob implements Job
{
    public function perform(array $arguments): void
    {
        throw new \Error('broken');
    }
}
