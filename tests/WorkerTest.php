<?php

declare(strict_types=1);

namespace Resque;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Resque\Interfaces\Dispatcher;
use Resque\Tasks\AfterUserJobPerform;
use Resque\Tasks\BeforeUserJobPerform;
use Resque\Tasks\BrokenUserJobPerform;
use Resque\Tasks\FailedUserJobPerform;
use Resque\Tasks\ForkFailed;
use Resque\Tasks\JobFailed;
use Resque\Tasks\ParentWaiting;
use Resque\Tasks\UnknownChildFailure;
use Resque\Tasks\WorkerDoneWorking;
use Resque\Tasks\WorkerIdle;
use Resque\Tasks\WorkerRegistering;
use Resque\Tasks\WorkerStartup;
use Resque\Tasks\WorkerUnregistering;
use Resque\Tests\Fixtures\BreakingUserJob;
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

        $this->jobHasFailed = false;
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
            ->willReturn('')
        ;

        $this->dispatcher->expects($this->exactly(4))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [WorkerIdle::class, ['worker' => $this->worker]],
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

    public function testWorkerShouldPerformFoundJobsAndHandleTheirErrorsInChildProcess()
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

        $failingJob = new BreakingUserJob();
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
            [BrokenUserJobPerform::class, $payload],
            [WorkerUnregistering::class, ['worker' => $this->worker]]
        );

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

        $this->dispatcher->expects($this->exactly(5))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [ForkFailed::class, $this->callback(function ($payload) {
                    $this->assertEquals($this->worker, $payload['worker']);
                    $this->assertInstanceOf(Job::class, $payload['job']);
                    return true;
                })],
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
                [ParentWaiting::class, ['worker' => $this->worker]],
                [WorkerDoneWorking::class, ['worker' => $this->worker]],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->pcntl_fork->expects($this->once())->willReturn(1);
        $this->pcntl_wait->expects($this->once())->with($this->callback(function ($status) {
            $this->assertNull($status);
            return true;
        }));

        $this->pcntl_wifexited->expects($this->once())->with(0)->willReturn(true);
        $this->pcntl_wexitstatus->expects($this->once())->with(0)->willReturn(0);

        $this->worker->work();

        $this->assertFalse($this->job->hasFailed(), "Job has failed, but should not have");
    }

    public function testWorkShouldRunNormallyWhenUserJobFailsRegularlyInParentProcess()
    {
        $this->jobHasFailed = true;
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

        $this->dispatcher->expects($this->exactly(6))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [ParentWaiting::class, ['worker' => $this->worker]],
                [JobFailed::class, $this->callback(function ($payload) {
                    $this->assertEquals($this->worker, $payload['worker']);
                    $this->assertInstanceOf(Job::class, $payload['job']);
                    $this->assertEquals(0, $payload['status']);
                    $this->assertEquals(0, $payload['exit_code']);
                    return true;
                })],
                [WorkerDoneWorking::class, ['worker' => $this->worker]],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->pcntl_fork->expects($this->once())->willReturn(1);
        $this->pcntl_wait->expects($this->once())->with($this->callback(function ($status) {
            $this->assertNull($status);
            return true;
        }));

        $this->pcntl_wifexited->expects($this->once())->with(0)->willReturn(false);
        $this->pcntl_wexitstatus->expects($this->never());

        $this->worker->work();
    }

    public function testWorkShouldStopAndShutdownWhenUserJobFailsIrregularlyInParentProcess()
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

        $this->dispatcher->expects($this->exactly(6))
            ->method('dispatch')
            ->withConsecutive(
                [WorkerStartup::class, ['worker' => $this->worker]],
                [WorkerRegistering::class, ['worker' => $this->worker]],
                [ParentWaiting::class, ['worker' => $this->worker]],
                [UnknownChildFailure::class, $this->callback(function ($payload) {
                    $this->assertEquals($this->worker, $payload['worker']);
                    $this->assertInstanceOf(Job::class, $payload['job']);
                    $this->assertEquals(0, $payload['status']);
                    $this->assertEquals(1, $payload['exit_code']);
                    return true;
                })],
                [WorkerDoneWorking::class, ['worker' => $this->worker]],
                [WorkerUnregistering::class, ['worker' => $this->worker]]
            )
        ;

        $this->pcntl_fork->expects($this->once())->willReturn(1);
        $this->pcntl_wait->expects($this->once())->with($this->callback(function ($status) {
            $this->assertNull($status);
            return true;
        }));

        $this->pcntl_wifexited->expects($this->once())->with(0)->willReturn(true);
        $this->pcntl_wexitstatus->expects($this->once())->with(0)->willReturn(1);

        $this->worker->work();
    }

    public function testForceShutdownShouldShutdownTheParentProcessSoftly()
    {
        $this->assertFalse($this->getShouldShutdownForWorker(), 'a fresh worker should not shutdown');
        $this->worker->forceShutdown();
        $this->assertTrue($this->getShouldShutdownForWorker(), 'the worker should shutdown after receiving the signal to do so');
    }

    public function testForceShutdownShouldShutdownTheChildProcessHarshly()
    {
        $childId = random_int(1, 1 << 16);
        $this->setChildIdForWorker($childId);
        $this->posix_kill->expects($this->once())->with($childId, SIGTERM)->willReturn(true);
        $this->assertFalse($this->getShouldShutdownForWorker(), 'a fresh worker should not shutdown');
        $this->worker->forceShutdown();
        $this->assertTrue($this->getShouldShutdownForWorker(), 'the worker should shutdown after receiving the signal to do so');
    }

    public function testForceShutdownShouldShutdownTheChildProcessAbruptlyWhenHarshDoesNotWork()
    {
        $childId = random_int(1, 1 << 16);
        $this->setChildIdForWorker($childId);
        $this->posix_kill->expects($this->exactly(2))
            ->withConsecutive(
                [$childId, SIGTERM],
                [$childId, SIGKILL]
            )
            ->willReturn(false)
        ;
        $this->assertFalse($this->getShouldShutdownForWorker(), 'a fresh worker should not shutdown');
        $this->worker->forceShutdown();
        $this->assertTrue($this->getShouldShutdownForWorker(), 'the worker should shutdown after receiving the signal to do so');
    }

    public function testPauseShouldSwitchThePauseIndicator()
    {
        $this->assertFalse($this->getIsPausedForWorker(), 'a fresh worker should not pause');
        $this->worker->pause();
        $this->assertTrue($this->getIsPausedForWorker(), 'a worker should pause after asked to');
    }

    public function testContinueShouldUnpauseAWorker()
    {
        $this->assertFalse($this->getIsPausedForWorker(), 'a fresh worker should not pause');
        $this->worker->pause();
        $this->assertTrue($this->getIsPausedForWorker(), 'a worker should pause after asked to');
        $this->worker->continue();
        $this->assertFalse($this->getIsPausedForWorker(), 'a worker should not pause after asked to continue');
    }

    public function getIsPausedForWorker()
    {
        $property = new \ReflectionProperty(get_class($this->worker), 'isPaused');
        $property->setAccessible(true);
        return $property->getValue($this->worker);
    }

    public function setChildIdForWorker($id)
    {
        $property = new \ReflectionProperty(get_class($this->worker), 'childId');
        $property->setAccessible(true);
        $property->setValue($this->worker, $id);
    }

    private function getShouldShutdownForWorker()
    {
        $property = new \ReflectionProperty(get_class($this->worker), 'shouldShutdown');
        $property->setAccessible(true);
        return $property->getValue($this->worker);
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
        return $this->getMockBuilder(Dispatcher::class)
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

            $property = new \ReflectionProperty(Job::class, 'failed');
            $property->setAccessible(true);
            $property->setValue($job, $that->jobHasFailed);

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
        $this->posix_kill = $this->getFunctionMock(__NAMESPACE__, 'posix_kill');
    }
}
