<?php

namespace Resque;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Resque\Interfaces\Dispatcher;
use Resque\Tasks\BeforeJobPop;
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
            'queue_key' => 'resque:queue:some_queue',
            'queue_name' => $queue_name,
            'json' => '{"data": "value"}',
            'command' => 'rpush',
        ];
        $modifiedPayload = [
            'queue_key' => 'resque:queue:some_queue',
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
            ->with('resque:queues', $modifiedPayload['queue_name'])
        ;

        $this->redis->expects($this->once())
            ->method($modifiedPayload['command'])
            ->with('resque:queue:some_queue', $modifiedPayload['json'])
        ;

        $this->datastore->pushToQueue($payload['queue_name'], $payload['json']);
    }

    public function testPopFromQueueShouldDispatchAndPop()
    {
        $payload = ['command' => 'lpop', 'queue_name' => 'some_queue'];
        $modifiedPayload = ['command' => 'rpop', 'queue_name' => 'some_queue'];

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(BeforeJobPop::class, $payload)
            ->willReturn($modifiedPayload)
        ;

        $this->redis->expects($this->once())
            ->method($modifiedPayload['command'])
            ->with('resque:queue:some_queue')
            ->willReturn('{"some": "data"}')
        ;

        $result = $this->datastore->popFromQueue($payload['queue_name']);
        $this->assertEquals('{"some": "data"}', $result);
    }

    public function testPopFromQueueShouldWorkWithNullResponseFromPredis()
    {
        $payload = ['command' => 'lpop', 'queue_name' => 'some_queue'];
        $modifiedPayload = ['command' => 'rpop', 'queue_name' => 'some_queue'];

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(BeforeJobPop::class, $payload)
            ->willReturn($modifiedPayload)
        ;

        $this->redis->expects($this->once())
            ->method($modifiedPayload['command'])
            ->with('resque:queue:some_queue')
            ->willReturn(null)
        ;

        $result = $this->datastore->popFromQueue($payload['queue_name']);
        $this->assertNull($result);
    }

    public function testPushToFailedQueueShouldDispatchAndPush()
    {
        $queue_name = 'failed';
        $payload = [
            'queue_key' => 'resque:queue:failed',
            'queue_name' => $queue_name,
            'json' => '{"data": "value"}',
            'command' => 'rpush',
        ];
        $modifiedPayload = [
            'queue_key' => 'resque:queue:failed',
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
            ->with('resque:queues', $modifiedPayload['queue_name'])
        ;

        $this->redis->expects($this->once())
            ->method($modifiedPayload['command'])
            ->with('resque:queue:failed', $modifiedPayload['json'])
        ;

        $this->datastore->pushToFailedQueue($payload['json']);
    }

    public function testRegisterWorkerShouldAddWorkerTracking()
    {
        $workerId = random_int(1, 1 << 16);
        $this->redis->expects($this->once())
            ->method('sadd')
            ->with('resque:workers', $workerId)
        ;

        $this->redis->expects($this->once())
            ->method('set')
            ->with('resque:worker:' . $workerId . ':started')
        ;

        $this->datastore->registerWorker($workerId);
    }

    public function testReconnectShouldDisconnectAndConnectAgain()
    {
        $this->redis->expects($this->once())->method('disconnect');
        $this->redis->expects($this->once())->method('connect');
        $this->datastore->reconnect();
    }

    public function testUnregisterWorkerShouldRemoveDatabaseAnnotations()
    {
        $workerId = random_int(1, 1 << 16);
        $this->redis->expects($this->once())->method('srem')->with('resque:workers', $workerId);
        $this->redis->expects($this->exactly(2))
            ->method('del')
            ->withConsecutive(['resque:worker:' . $workerId], ['resque:worker:' . $workerId . ':started'])
        ;

        $this->datastore->unregisterWorker($workerId);
    }

    public function testSetWorkerPayloadShouldSetThePayloadInRedis()
    {
        $data = 'some data';
        $workerId = random_int(1, 1 << 16);
        $this->redis->expects($this->once())
            ->method('set')
            ->with('resque:worker:' . $workerId, $data)
        ;

        $this->datastore->setWorkerPayload($workerId, $data);
    }

    public function testWorkerDoneWorkignShouldUnregisterTheWorkerInRedis()
    {
        $workerId = random_int(1, 1 << 16);
        $this->redis->expects($this->once())
            ->method('del')
            ->with('resque:worker:' . $workerId)
        ;

        $this->datastore->workerDoneWorking($workerId);
    }

    public function testSetNamespaceChangesTheResqueKeys()
    {
        $this->datastore->setNamespace('some_other_namespace');
        $workerId = random_int(1, 1 << 16);
        $this->redis->expects($this->once())
            ->method('del')
            ->with('some_other_namespace:worker:' . $workerId)
        ;

        $this->datastore->workerDoneWorking($workerId);
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
                'srem',
                'hdel',
                'set',
                'del',
                'disconnect',
                'connect',
            ])
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
}
