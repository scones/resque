<?php

declare(strict_types=1);

namespace Resque\Traits;

trait Payloadable
{
    private $payload;

    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
}
