<?php

namespace Resque\Tests\Fixtures;

use Resque\Interfaces\JobInterface;

class FailingUserJob implements JobInterface
{

    public function perform(array $arguments): void
    {
        throw new \Exception('failing');
    }

}
