<?php

declare(strict_types=1);

namespace Resque\Tasks;

use Resque\Interfaces\PayloadableTaskInterface;
use Resque\Traits\Payloadable;

class BeforeJobPush implements PayloadableTaskInterface
{
    use Payloadable;
}
