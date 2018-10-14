<?php

declare(strict_types=1);

namespace Resque\Interfaces;

interface Payloadable
{
    public function setPayload(array $payload): void;
    public function getPayload(): array;
}
