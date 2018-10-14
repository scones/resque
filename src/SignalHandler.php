<?php

declare(strict_types=1);

namespace Resque;

class SignalHandler
{
    private $worker;
    private $dispatcher;

    private $signals = [
        SIGTERM => 'shutdown',
        SIGINT => 'shutdown',
        SIGQUIT => 'shutdown',
        SIGUSR1 => 'pause',
        SIGCONT => 'continue',
        SIGUSR2 => 'forceShutdown',
    ];

    public function __construct(Worker $worker, PayloadDispatcher $dispatcher, array $signals = [])
    {
        $this->worker = $worker;
        $this->dispatcher = $dispatcher;

        foreach ($signals as $type => $signalCallback) {
            if (in_array($type, array_keys($this->signals)) && is_callable($signalCallback)) {
                $this->signals[$type] = $signalCallback;
            }
        }
    }

    public function register(): void
    {
        pcntl_async_signals(true);
        foreach ($this->signals as $signalType => $signalHandler) {
            pcntl_signal($signalType, [$this->worker, $this->$signalHandler]);
        }

        register_shutdown_function([$this, 'unregisterSignalHandlers']);
    }
}
