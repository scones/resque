<?php

namespace Resque\Dispatchers;

use PHPUnit\Framework\TestCase;

class NoopTest extends TestCase
{
    public function testDispatchShouldNotDoAnything()
    {
        $noop = new Noop();
        $payload = ['some' => 'payload'];
        $this->assertEquals($payload, $noop->dispatch('SomeClass', $payload));
    }
}
