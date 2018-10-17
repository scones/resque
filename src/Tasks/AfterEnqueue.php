<?php

declare(strict_types=1);

namespace Resque\Tasks;

use Resque\Interfaces\PayloadableTask;
use Resque\Traits\Payloadable;

class AfterEnqueue implements PayloadableTask
{
    use Payloadable;
}
