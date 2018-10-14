<?php

declare(strict_types=1);

namespace Resque\Tasks;

use Resque\Interfaces\PayloadableTaskInterface;
use Resque\Traits\Payloadable;

class WorkerStartup implements PayloadableTaskInterface
{
    use Payloadable;
}
