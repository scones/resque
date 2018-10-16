<?php

namespace Resque;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Resque\Interfaces\DispatcherInterface;
use Resque\Tasks\BeforeJobPush;

class DatastoreTest extends TestCase
{
    public function setUp()
    {
        $this->redis = $this->getRedisMock();
        $this->dispatcher = $this->getDispatcherMock();
        $this->resqueNamespace = 'resque';
        $this->datastore = new DataStore($this->redis, $this->resqueNamespace);
        $this->datastore->setDispatcher($this->dispatcher);
    }

    public function tearDown()
    {
    }

    public function testPushToQueueShouldDispatchAndPush()
    {
        $queue_name = 'some_queue';
        $payload = [
            'queue_key' => 'queue:some_queue',
            'queue_name' => $queue_name,
            'json' => '{"data": "value"}',
            'command' => 'rpush',
        ];
        $modifiedPayload = [
            'queue_key' => 'queue:some_queue',
            'queue_name' => $queue_name,
            'json' => '{"data": "value"}',
            'command' => 'lpush',
        ];
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(BeforeJobPush::class, $payload)
            ->willReturn($modifiedPayload)
        ;

        $this->redis->expects($this->once())
            ->method('sadd')
            ->with('queues', $modifiedPayload['queue_name'])
        ;

        $this->redis->expects($this->once())
            ->method($modifiedPayload['command'])
            ->with('queue:some_queue', $modifiedPayload['json'])
        ;

        $this->datastore->pushToQueue($payload['queue_name'], $payload['json']);
    }

    private function getRedisMock()
    {
        return $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'rpush',
                'lpush',
                'sadd',
                'lpop',
                'rpop',
                'get',
                'sismember',
                'srem',
                'hdel',
                'hget',
                'hgetall',
                'set',
                'incrby',
                'decrby',
                'del',
                'disconnect',
                'reconnect',
            ])
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