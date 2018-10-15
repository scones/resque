<?php

declare(strict_types=1);

namespace Resque;

use Resque\Dispatchers\Noop;
use Resque\Interfaces\DispatcherInterface;
use Resque\Tasks\BeforeSignalsRegister;

class SignalHandler
{
    private $worker = null;
    private $dispatcher;

    private $signals = [
        SIGTERM => 'shutdown',
        SIGINT => 'shutdown',
        SIGQUIT => 'shutdown',
        SIGUSR1 => 'pause',
        SIGCONT => 'continue',
        SIGUSR2 => 'forceShutdown',
    ];

    public function __construct(array $signals = [])
    {
        foreach ($signals as $type => $signalCallback) {
            if (in_array($type, array_keys($this->signals)) && is_callable($signalCallback)) {
                $this->signals[$type] = $signalCallback;
            }
        }
        $this->dispatcher = new Noop();
    }

    public function setDispatcher(DispatcherInterface $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    public function setWorker(Worker $worker): void
    {
        $this->worker = $worker;
    }

    public function register(): void
    {
        $payload = $this->dispatcher->dispatch(BeforeSignalsRegister::class, ['signals' => $this->signals]);
        pcntl_async_signals(true);
        foreach ($payload['signals'] as $signalType => $signalHandler) {
            pcntl_signal($signalType, [$this->worker, $signalHandler]);
        }

        register_shutdown_function([$this, 'unregister']);
    }

    public function unregister(): void
    {
        pcntl_async_signals(false);
    }
}
