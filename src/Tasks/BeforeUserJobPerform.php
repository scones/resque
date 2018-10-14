<?php

declare(strict_types=1);

namespace Resque\Tasks;

use Psr\EventDispatcher\TaskInterface;
use Resque\Interfaces\Payloadable as PayloadInterface;
use Resque\Traits\Payloadable;

class BeforeUserJobPerform implements PayloadInterface, TaskInterface
{
    use Payloadable;
}
