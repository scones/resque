<?php

namespace Resque\Interfaces;

use Psr\EventDispatcher\TaskInterface;

interface PayloadableTask extends TaskInterface, Payloadable
{
}
