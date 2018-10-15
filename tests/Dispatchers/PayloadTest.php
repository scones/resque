<?php

namespace Resque\Dispatchers;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\TaskProcessorInterface;
use Resque\Interfaces\PayloadableTaskInterface;

class PayloadTest extends TestCase
{
    public function setUp()
    {
        $this->serviceLocator = $this->getServiceLocatorMock();
        $this->processor = $this->getTaskProcessorMock();
        $this->payloadable = $this->getPayloadableTaskMock();
        $this->dispatcher = new Payload($this->serviceLocator, $this->processor);
    }

    public function tearDown()
    {
    }

    public function testDispatchShouldDispatchTheTaskWithPayloadAndReturnTheProcessedPayload()
    {
        $className = 'SomeClass';
        $payload = ['some_key' => 'some_value'];
        $processedPayload = ['some_new_key' => 'some_other_value'];
        $this->serviceLocator->expects($this->once())
            ->method('get')
            ->with($className)
            ->willReturn($this->payloadable)
        ;

        $this->processor->expects($this->once())
            ->method('process')
            ->with($this->payloadable)
            ->willReturn($this->payloadable)
        ;

        $this->payloadable->expects($this->once())
            ->method('setPayload')
            ->with($payload)
        ;

        $this->payloadable->expects($this->once())
            ->method('getPayload')
            ->willReturn($processedPayload)
        ;

        $payloadInQuestion = $this->dispatcher->dispatch($className, $payload);

        $this->assertEquals($processedPayload, $payloadInQuestion);
    }

    private function getServiceLocatorMock()
    {
        return $this->getMockBuilder(ContainerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'has'])
            ->getMock()
        ;
    }

    private function getTaskProcessorMock()
    {
        return $this->getMockBuilder(TaskProcessorInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['process'])
            ->getMock()
        ;
    }

    private function getPayloadableTaskMock()
    {
        return $this->getMockBuilder(PayloadableTaskInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getPayload', 'setPayload'])
            ->getMock()
        ;
    }
}
