<?php

declare(strict_types=1);

namespace Resque;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Resque\Tests\Fixtures\FailingUserJob;
use Resque\Tests\Fixtures\PassingUserJob;

class WorkerTest extends TestCase
{
    use \phpmock\phpunit\PHPMock;

    private $worker;

    public function setUp()
    {
        $this->datastore = $this->getDatastoreMock();
        $this->serviceLocator = $this->getServiceLocatorMock();
        $this->signalHandler = $this->getSignalHandlerMock();
        $this->dispatcher = $this->getDispatcherMock();
        $serializer = new JsonSerializer();
        $queueNames = ['test_queue'];
        $interval = 10;
        $this->worker = new Worker($this->datastore, $serializer, $this->serviceLocator, $this->signalHandler);
        $this->worker->setInterval(0);
        $this->worker->setQueueNames($queueNames);

        $this->jobBuilder = $this->getJobMock();
        $this->mockForking();

        uopz_allow_exit(false);
    }

    public function tearDown()
    {
        uopz_allow_exit(true);
    }

    public function testWorkerShouldWaitForJobs()
    {
        $this->datastore->expects($this->once())
            ->method('popFromQueue')
            ->with('test_queue')
            ->willReturn(null)
        ;

        $this->pcntl_fork->expects($this->never());

        $this->worker->work();
    }

    public function testWorkerShouldPerformFoundJobs()
    {
        $className = 'SomeJobClass';
        $payload = [
            'class' => $className,
            'args' => [
                'some_argument' => 'some value',
                'some_other_arg' => 123,
            ]
        ];
        $this->datastore->expects($this->once())
            ->method('popFromQueue')
            ->with('test_queue')
            ->willReturn(json_encode($payload))
        ;

        $this->serviceLocator->expects($this->once())
            ->method('get')
            ->with(Job::class)
            ->willReturn($this->jobBuilder)
        ;

        $passingJob = new PassingUserJob;
        $this->jobServiceLocator->expects($this->any())
            ->method('get')
            ->with($className)
            ->willReturn($passingJob)
        ;

        $this->pcntl_fork->expects($this->once())->willReturn(0);

        $this->worker->work();

        $this->assertFalse($this->job->hasFailed(), "Job has failed, but should not have");
    }

    public function testWorkerShouldPerformFoundJobsAndHandleTheirExceptions()
    {
        $className = 'SomeJobClass';
        $payload = [
            'class' => $className,
            'args' => [
                'some_argument' => 'some value',
                'some_other_arg' => 123,
            ]
        ];
        $this->datastore->expects($this->once())
            ->method('popFromQueue')
            ->with('test_queue')
            ->willReturn(json_encode($payload))
        ;

        $this->serviceLocator->expects($this->once())
            ->method('get')
            ->with(Job::class)
            ->willReturn($this->jobBuilder)
        ;

        $failingJob = new FailingUserJob();
        $this->jobServiceLocator->expects($this->any())
            ->method('get')
            ->with($className)
            ->willReturn($failingJob)
        ;

        $this->worker->work();

        $this->assertTrue($this->job->hasFailed(), "Job has not failed, but should have");
    }

    private function getDatastoreMock()
    {
        return $this->getMockBuilder(Datastore::class)
            ->disableOriginalConstructor()
            ->setMethods(['popFromQueue', 'registerWorker', 'unregisterWorker', 'setWorkerPayload', 'reconnect'])
            ->getMock()
        ;
    }

    private function getServiceLocatorMock()
    {
        return $this->getMockBuilder(ContainerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'has'])
            ->getMock()
        ;
    }

    private function getSignalHandlerMock()
    {
        return $this->getMockBuilder(SignalHandler::class)
            ->disableOriginalConstructor()
            ->setMethods(['register'])
            ->getMock()
        ;
    }

    private function getDispatcherMock()
    {
        return $this->getMockBuilder(PayloadDispatcher::class)
            ->disableOriginalConstructor()
            ->setMethods(['dispatch'])
            ->getMock()
        ;
    }

    private function getJobMock()
    {
        $this->jobServiceLocator = $locator = $this->getMockBuilder(ContainerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'has'])
            ->getMock()
        ;
        $that = $this;
        return function($className, $arguments) use ($locator, $that) {
            $job = new Job($className, $arguments, $locator);
            $that->job = $job;
            return $job;
        };
    }

    private function mockForking()
    {
        $this->pcntl_fork = $this->getFunctionMock(__NAMESPACE__, 'pcntl_fork');
        $this->pcntl_wait = $this->getFunctionMock(__NAMESPACE__, 'pcntl_wait');
        $this->pcntl_wifexited = $this->getFunctionMock(__NAMESPACE__, 'pcntl_wifexited');
        $this->pcntl_wexitstatus = $this->getFunctionMock(__NAMESPACE__, 'pcntl_wexitstatus');
    }
}
