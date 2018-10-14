<?php

declare(strict_types=1);

namespace Resque\Tasks;

use Psr\EventDispatcher\TaskInterface;
use Resque\Interfaces\Payloadable as PayloadableInterface;
use Resque\Traits\Payloadable;

class BeforeEnqueue implements PayloadableInterface, TaskInterface
{
    use Payloadable;
}
