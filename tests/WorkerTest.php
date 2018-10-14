<?php

declare(strict_types=1);

namespace Resque;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Resque\Interfaces\DispatcherInterface;
use Resque\Tasks\AfterUserJobPerform;
use Resque\Tasks\BeforeUserJobPerform;
use Resque\Tasks\FailedUserJobPerform;
use Resque\Tasks\WorkerDoneWorking;
use Resque\Tasks\WorkerRegistering;
use Resque\Tasks\WorkerStartup;
use Resque\Tasks\WorkerUnregistering;
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
        $this->worker = new Worker($this->datastore, $serializer, $this->serviceLocator, $this->signalHandler);
        $this->worker->setInterval(0);
        $this->worker->setQueueNames($queueNames);
        $this->worker->setDispatcher($this->dispatcher);

        $this->jobBuilder = $this->getJobMock();
        $this->mockForking();
    }

    public function tearDown()
    {
    }

    public function testWorkerShouldWaitForJobs()
    {
        $this->datastore->expects($this->once())
            ->method('popFromQueue')
            ->with('test_queue')
            ->willReturn(null)
        ;

        $this->dispatcher->expects($this->exactly(3))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->pcntl_fork->expects($this->never());

        $this->worker->work();
    }

    public function testWorkerShouldPerformFoundJobsInChildProcess()
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

        $passingJob = new PassingUserJob();
        $this->jobServiceLocator->expects($this->any())
            ->method('get')
            ->with($className)
            ->willReturn($passingJob)
        ;

        $this->dispatcher->expects($this->exactly(5))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [BeforeUserJobPerform::class, $payload],
                [AfterUserJobPerform::class, $payload],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->pcntl_fork->expects($this->once())->willReturn(0);

        $this->worker->work();

        $this->assertFalse($this->job->hasFailed(), "Job has failed, but should not have");
    }

    public function testWorkerShouldPerformFoundJobsAndHandleTheirExceptionsInChildProcess()
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

        $this->dispatcher->expects($this->exactly(5))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [BeforeUserJobPerform::class, $payload],
                [FailedUserJobPerform::class, $payload],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->worker->work();

        $this->assertTrue($this->job->hasFailed(), "Job has not failed, but should have");
    }

    public function testWorkShouldShutdownImmediatelyWhenTheForkFails()
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
        $this->pcntl_fork->expects($this->once())->willReturn(-1);

        $this->dispatcher->expects($this->exactly(4))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [WorkerDoneWorking::class, ['worker' => $this->worker]],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->worker->work();
    }

    public function testWorkShouldRunNormallyWhenUserJobSucceedsInParentProcess()
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

        $this->dispatcher->expects($this->exactly(4))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [WorkerDoneWorking::class, ['worker' => $this->worker]],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->pcntl_fork->expects($this->once())->willReturn(1);

        $this->worker->work();

        $this->assertFalse($this->job->hasFailed(), "Job has failed, but should not have");
    }

    public function testWorkShouldRunNormallyWhenUserJobFailsInParentProcess()
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

        $passingJob = new PassingUserJob();
        $this->jobServiceLocator->expects($this->any())
            ->method('get')
            ->with($className)
            ->willReturn($passingJob)
        ;

        $this->dispatcher->expects($this->exactly(4))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [WorkerDoneWorking::class, ['worker' => $this->worker]],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->pcntl_fork->expects($this->once())->willReturn(1);

        $this->worker->work();

        $this->assertFalse($this->job->hasFailed(), "Job has failed, but should not have");
    }

    private function getDatastoreMock()
    {
        return $this->getMockBuilder(Datastore::class)
            ->disableOriginalConstructor()
            ->setMethods(['pushToQueue', 'popFromQueue', 'registerWorker', 'unregisterWorker', 'setWorkerPayload', 'reconnect'])
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
        return $this->getMockBuilder(DispatcherInterface::class)
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
        return function ($className, $arguments) use ($locator, $that) {
            $job = new Job($className, $arguments, $locator);
            $job->setDispatcher($that->dispatcher);
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
