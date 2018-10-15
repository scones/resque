<?php

namespace Resque\Traits;

use PHPUnit\Framework\TestCase;

class PayloadableTest extends TestCase
{
    public function setUp()
    {
        $this->payloadable = $this->getMockForTrait(Payloadable::class);
    }

    public function tearDown()
    {
    }

    public function testSetPAyloadAndGetPayloadShouldWork()
    {
        $payload = ['some_value' => bin2hex(random_bytes(21))];
        $this->payloadable->setPayload($payload);
        $this->assertEquals($payload, $this->payloadable->getPayload());
    }
}
