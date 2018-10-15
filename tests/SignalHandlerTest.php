<?php

namespace Resque;

use PHPUnit\Framework\TestCase;

class SignalHandlerTest extends TestCase
{
    use \phpmock\phpunit\PHPMock;

    public function setUp()
    {
        $this->pcntl_signal = $this->getFunctionMock(__NAMESPACE__, 'pcntl_signal');
        $this->pcntl_async_signals = $this->getFunctionMock(__NAMESPACE__, 'pcntl_async_signals');
        $this->worker = $this->getMockBuilder(Worker::class)->disableOriginalConstructor()->getMock();
    }

    public function tearDown()
    {
    }

    public function testRegisterShouldRegisterTheDefaultSignalsWhenNothingElseIsSet()
    {
        $signalHandler = new SignalHandler();
        $signalHandler->setWorker($this->worker);
        $signalsMap = $this->getCurrentSignalMap($signalHandler);

        foreach ($signalsMap as $i => $signal) {
            $this->pcntl_signal->expects($this->at($i))->with($signal[0], $signal[1]);
        }

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
        $signalsMap = $this->getCurrentSignalMap($signalHandler);

        foreach ($signalsMap as $i => $signal) {
            $this->pcntl_signal->expects($this->at($i))->with($signal[0], $signal[1]);
        }

        $signalHandler->register();
    }

    private function getCurrentSignalMap(SignalHandler $signalHandler)
    {
        $property = new \ReflectionProperty(get_class($signalHandler), 'signals');
        $property->setAccessible(true);
        $currentSignals = $property->getValue($signalHandler);

        $signalsMap = [];
        foreach ($currentSignals as $signalType => $callbackName) {
            $signalsMap[] = [$signalType, [$this->worker, $callbackName]];
        }
        return $signalsMap;
    }
}
