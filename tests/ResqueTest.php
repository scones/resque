<?php

namespace Resque;

use PHPUnit\Framework\TestCase;
use Resque\Exceptions\JobClassMissing;
use Resque\Exceptions\QueueMissing;
use Resque\Interfaces\DispatcherInterface;
use Resque\Tasks\AfterEnqueue;
use Resque\Tasks\BeforeEnqueue;

class ResqueTest extends TestCase
{
    public function setUp()
    {
        $this->datastore = $this->getDatastoreMock();
        $this->serializer = new JsonSerializer();
        $this->resque = new Resque($this->datastore, $this->serializer);

        $this->dispatcher = $this->getDispatcherMock();
        $this->resque->setDispatcher($this->dispatcher);
    }

    public function tearDown()
    {
    }

    public function testEnqueueShouldEnqueueAJob()
    {
        $queueName = 'some_queue';
        $className = 'SomeUserJobClass';
        $arguments = ['foo' => 'bar'];
        $payload = ['class' => $className, 'args' => $arguments];
        $this->datastore->expects($this->once())
            ->method('pushToQueue')
            ->with($queueName, $this->serializer->serialize($payload))
        ;

        $this->dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->withConsecutive([BeforeEnqueue::class, $payload], [AfterEnqueue::class, $payload])
            ->will($this->onConsecutiveCalls($payload, $payload))
        ;

        $this->resque->enqueue($className, ['foo' => 'bar'], $queueName);
    }

    public function testEnqueueShouldThrowWhenNoQueueNameIsGiven()
    {
        $this->expectException(QueueMissing::class);

        $queueName = '';
        $className = 'SomeUserJobClass';
        $this->datastore->expects($this->never())->method('pushToQueue');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->resque->enqueue($className, ['foo' => 'bar'], $queueName);
    }

    public function testEnqueueShouldThrowWhenNoUserJobClassIsGiven()
    {
        $this->expectException(JobClassMissing::class);

        $queueName = 'some_queue';
        $className = '';
        $this->datastore->expects($this->never())->method('pushToQueue');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->resque->enqueue($className, ['foo' => 'bar'], $queueName);
    }

    private function getDatastoreMock()
    {
        return $this->getMockBuilder(Datastore::class)
            ->disableOriginalConstructor()
            ->setMethods(['pushToQueue'])
            ->getMock()
        ;
    }

    private function getDispatcherMock()
    {
        return $this->getMockBuilder(DispatcherInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['dispatch'])
            ->getMock()
        ;
    }
}
