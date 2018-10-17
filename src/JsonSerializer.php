<?php

declare(strict_types=1);

namespace Resque;

use Resque\Interfaces\Serializer;

class JsonSerializer implements Serializer
{
    public function serialize(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    public function unserialize(string $payloadString): array
    {
        return json_decode($payloadString, true);
    }
}
