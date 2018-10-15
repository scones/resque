<?php

namespace Resque;

use PHPUnit\Framework\TestCase;
use Resque\Interfaces\DispatcherInterface;
use Resque\Tasks\BeforeSignalsRegister;

class SignalHandlerTest extends TestCase
{
    use \phpmock\phpunit\PHPMock;

    public function setUp()
    {
        $this->pcntl_signal = $this->getFunctionMock(__NAMESPACE__, 'pcntl_signal');
        $this->pcntl_async_signals = $this->getFunctionMock(__NAMESPACE__, 'pcntl_async_signals');
        $this->register_shutdown_function = $this->getFunctionMock(__NAMESPACE__, 'register_shutdown_function');
        $this->worker = $this->getMockBuilder(Worker::class)->disableOriginalConstructor()->getMock();
        $this->dispatcher = $this->getDispatcherMock();
    }

    public function tearDown()
    {
    }

    public function testRegisterShouldRegisterTheDefaultSignalsWhenNothingElseIsSet()
    {
        $signalHandler = new SignalHandler();
        $signalHandler->setWorker($this->worker);
        $signalHandler->setDispatcher($this->dispatcher);
        $signalsMap = $this->getCurrentSignalMap($signalHandler);

        foreach ($signalsMap as $i => $signal) {
            $this->pcntl_signal->expects($this->at($i))->with($signal[0], $signal[1]);
        }

        $payload = ['signals' => $this->getSignals($signalHandler)];
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(BeforeSignalsRegister::class, $payload)
            ->willReturn($payload)
        ;

        $this->register_shutdown_function->expects($this->once())->with([$signalHandler, 'unregister']);

        $signalHandler->register();
    }

    public function testRegisterShouldRegisterThePropriatarySignalsWhenGiven()
    {
        $signalHandler = new SignalHandler([
            SIGTERM => 'foo1',
            SIGINT => 'foo2',
            SIGQUIT => 'foo3',
            SIGUSR1 => 'foo4',
            SIGCONT => 'foo5',
            SIGUSR2 => 'foo6',
        ]);
        $signalHandler->setWorker($this->worker);
        $signalHandler->setDispatcher($this->dispatcher);
        $signalsMap = $this->getCurrentSignalMap($signalHandler);

        $payload = ['signals' => $this->getSignals($signalHandler)];
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(BeforeSignalsRegister::class, $payload)
            ->willReturn($payload)
        ;

        foreach ($signalsMap as $i => $signal) {
            $this->pcntl_signal->expects($this->at($i))->with($signal[0], $signal[1]);
        }

        $this->register_shutdown_function->expects($this->once())->with([$signalHandler, 'unregister']);

        $signalHandler->register();
    }

    public function testUnregisterShouldTurnOffSignals()
    {
        $signalHandler = new SignalHandler();
        $this->pcntl_async_signals->expects($this->once())->with(false);
        $signalHandler->unregister();
    }

    private function getCurrentSignalMap(SignalHandler $signalHandler)
    {
        $currentSignals = $this->getSignals($signalHandler);

        $signalsMap = [];
        foreach ($currentSignals as $signalType => $callbackName) {
            $signalsMap[] = [$signalType, [$this->worker, $callbackName]];
        }
        return $signalsMap;
    }

    private function getSignals(SignalHandler $signalHandler)
    {
        $property = new \ReflectionProperty(get_class($signalHandler), 'signals');
        $property->setAccessible(true);
        return $property->getValue($signalHandler);
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
