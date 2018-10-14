<?php

namespace Resque\Interfaces;

use Psr\EventDispatcher\TaskInterface;

interface PayloadableTaskInterface extends TaskInterface, Payloadable
{
}
