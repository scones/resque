<?php

namespace Resque;
use Resque\NoopLogger;
use PHPUnit\Framework\TestCase;

class NoopLoggerTest extends TestCase
{

    public function setUp()
    {
        $this->logger = new NoopLogger;
        $this->levels = [
            'emergency',
            'alert',
            'critical',
            'error',
            'warning',
            'notice',
            'info',
            'debug',
        ];
    }

    public function tearDown()
    {
    }

    public function testLoggersDoNothing()
    {
        foreach ($this->levels as $level) {
            $this->assertNull($this->logger->$level("some {$level} message"), "{$level} handler did too much");
        }
    }

    public function testLogDoesNothing()
    {
        foreach ($this->levels as $level) {
            $this->assertNull($this->logger->log($level, "some {$level} message"), "log method did more than nothing for '{$level}'");
        }
    }

}
