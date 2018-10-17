<?php

declare(strict_types=1);

namespace Resque\Tasks;

use Resque\Interfaces\PayloadableTask;
use Resque\Traits\Payloadable;

class BeforeJobPop implements PayloadableTask
{
    use Payloadable;
}
