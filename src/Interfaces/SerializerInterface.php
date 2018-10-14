<?php

declare(strict_types=1);

namespace Resque\Interfaces;

interface SerializerInterface
{
    public function serialize(array $payload): string;
    public function unserialize(string $payloadString): array;
}
