<?php

use Islambey\RSMQ\RSMQ;

class RSMQTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var RSMQ
     */
    private $rsmq;

    public function setUp(): void
    {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $this->rsmq = new RSMQ($redis);
    }

    public function testScriptsShouldInitialized()
    {
        $reflection = new ReflectionClass($this->rsmq);

        $recvMsgRef = $reflection->getProperty('receiveMessageSha1');
        $recvMsgRef->setAccessible(true);

        $this->assertSame(40, strlen($recvMsgRef->getValue($this->rsmq)));

        $popMsgRef = $reflection->getProperty('popMessageSha1');
        $popMsgRef->setAccessible(true);

        $this->assertSame(40, strlen($popMsgRef->getValue($this->rsmq)));
    }

    public function testCreateQueue()
    {
        $this->assertTrue($this->rsmq->createQueue('foo'));
    }

    public function testCreateQueueWithInvalidName()
    {
        $this->expectException(\Islambey\RSMQ\Exception::class);
        $this->expectExceptionMessage('Invalid queue name');
        $this->rsmq->createQueue(' sad');
    }

    public function testCreateQueueWithBigVt()
    {
        $this->expectException(\Islambey\RSMQ\Exception::class);
        $this->expectExceptionMessage('Visibility time must be between');
        $this->rsmq->createQueue('foo', PHP_INT_MAX);
    }

    public function testCreateQueueWithNegativeVt()
    {
        $this->expectException(\Islambey\RSMQ\Exception::class);
        $this->expectExceptionMessage('Visibility time must be between');
        $this->rsmq->createQueue('foo', -1);
    }

    public function testCreateQueueWithBigDelay()
    {
        $this->expectException(\Islambey\RSMQ\Exception::class);
        $this->expectExceptionMessage('Delay must be between');
        $this->rsmq->createQueue('foo', 30, PHP_INT_MAX);
    }

    public function testCreateQueueWithNegativeDelay()
    {
        $this->expectException(\Islambey\RSMQ\Exception::class);
        $this->expectExceptionMessage('Delay must be between');
        $this->rsmq->createQueue('foo', 30, -1);
    }

    public function testCreateQueueWithBigMaxSize()
    {
        $this->expectException(\Islambey\RSMQ\Exception::class);
        $this->expectExceptionMessage('Maximum message size must be between');
        $this->rsmq->createQueue('foo', 30, 0, PHP_INT_MAX);
    }

    public function testCreateQueueWithSmallMaxSize()
    {
        $this->expectException(\Islambey\RSMQ\Exception::class);
        $this->expectExceptionMessage('Maximum message size must be between');
        $this->rsmq->createQueue('foo', 30, 0, 1023);
    }

    public function testGetQueueAttributes()
    {
        $vt = 40;
        $delay = 60;
        $maxSize = 1024;
        $this->rsmq->createQueue('foo', $vt, $delay, $maxSize);

        $attributes = $this->rsmq->getQueueAttributes('foo');

        $this->assertSame($vt, $attributes['vt']);
        $this->assertSame($delay, $attributes['delay']);
        $this->assertSame($maxSize, $attributes['maxsize']);
    }

    public function testGetQueueAttributesThatDoesNotExists()
    {
        $this->expectExceptionMessage('Queue not found.');
        $this->rsmq->getQueueAttributes('not_existent_queue');
    }

    public function testCreateQueueMustThrowExceptionWhenQueueExists()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Queue already exists.');

        $this->rsmq->createQueue('foo');
        $this->rsmq->createQueue('foo');
    }

    public function testListQueues()
    {
        $this->assertEmpty($this->rsmq->listQueues());

        $this->rsmq->createQueue('foo');
        $this->assertSame(['foo'], $this->rsmq->listQueues());
    }

    public function testValidateWithInvalidQueueName()
    {
        $this->expectExceptionMessage('Invalid queue name');
        $this->invokeMethod($this->rsmq, 'validate', [
            ['queue' => ' foo']
        ]);

    }

    public function testValidateWithInvalidVt()
    {
        $this->expectExceptionMessage('Visibility time must be');
        $this->invokeMethod($this->rsmq, 'validate', [
            ['vt' => '-1']
        ]);
    }

    public function testValidateWithInvalidId()
    {
        $this->expectExceptionMessage('Invalid message id');
        $this->invokeMethod($this->rsmq, 'validate', [
            ['id' => '123456']
        ]);
    }

    public function testValidateWithInvalidDelay()
    {
        $this->expectExceptionMessage('Delay must be');
        $this->invokeMethod($this->rsmq, 'validate', [
            ['delay' => 99999999]
        ]);
    }

    public function testValidateWithInvalidMaxSize()
    {
        $this->expectExceptionMessage('Maximum message size must be');
        $this->invokeMethod($this->rsmq, 'validate', [
            ['maxsize' => 512]
        ]);
    }

    public function testSendMessage()
    {
        $this->rsmq->createQueue('foo');
        $id = $this->rsmq->sendMessage('foo', 'foobar');
        $this->assertSame(32, strlen($id));
    }

    public function testSendMessageWithBigMessage()
    {
        $this->rsmq->createQueue('foo');
        $bigStr = str_repeat(bin2hex(random_bytes(512)), 100);

        $this->expectExceptionMessage('Message too long');
        $this->rsmq->sendMessage('foo', $bigStr);
    }

    public function testDeleteMessage()
    {
        $this->rsmq->createQueue('foo');
        $id = $this->rsmq->sendMessage('foo', 'bar');
        $this->assertTrue($this->rsmq->deleteMessage('foo', $id));
    }

    public function testReceiveMessage()
    {
        $queue = 'foo';
        $message = 'Hello World';
        $this->rsmq->createQueue($queue);
        $id = $this->rsmq->sendMessage($queue, $message);
        $received = $this->rsmq->receiveMessage($queue);

        $this->assertSame($message, $received['message']);
        $this->assertSame($id, $received['id']);
    }

    public function testReceiveMessageWhenNoMessageExists()
    {
        $queue = 'foo';
        $this->rsmq->createQueue($queue);
        $received = $this->rsmq->receiveMessage($queue);

        $this->assertEmpty($received);
    }

    public function testChangeMessageVisibility()
    {
        $queue = 'foo';
        $this->rsmq->createQueue($queue);
        $id = $this->rsmq->sendMessage($queue, 'bar');
        $this->assertTrue($this->rsmq->changeMessageVisibility($queue, $id, 60));

    }

    public function testGetQueue()
    {
        $queueName = 'foo';
        $vt = 30;
        $delay = 0;
        $maxSize = 65536;
        $this->rsmq->createQueue($queueName, $vt, $delay, $maxSize);
        $queue = $this->invokeMethod($this->rsmq, 'getQueue', [$queueName, true]);

        $this->assertSame($vt, $queue['vt']);
        $this->assertSame($delay, $queue['delay']);
        $this->assertSame($maxSize, $queue['maxsize']);
        $this->assertArrayHasKey('uid', $queue);
        $this->assertSame(32, strlen($queue['uid']));
    }

    public function testGetQueueNotFound()
    {
        $this->expectExceptionMessage('Queue not found');
        $this->invokeMethod($this->rsmq, 'getQueue', ['notfound']);
    }

    public function testPopMessage()
    {
        $queue = 'foo';
        $message = 'bar';
        $this->rsmq->createQueue($queue);

        $id = $this->rsmq->sendMessage($queue, $message);
        $received = $this->rsmq->popMessage($queue);

        $this->assertSame($id, $received['id']);
        $this->assertSame($message, $received['message']);
    }

    public function testPopMessageWhenNoMessageExists()
    {
        $queue = 'foo';
        $this->rsmq->createQueue($queue);

        $received = $this->rsmq->popMessage($queue);

        $this->assertEmpty($received);

    }

    public function testSetQueueAttributes()
    {
        $queue = 'foo';
        $vt = 100;
        $delay = 10;
        $maxsize = 2048;
        $this->rsmq->createQueue($queue);
        $attrs = $this->rsmq->setQueueAttributes($queue, $vt, $delay, $maxsize);

        $this->assertSame($vt, $attrs['vt']);
        $this->assertSame($delay, $attrs['delay']);
        $this->assertSame($maxsize, $attrs['maxsize']);
    }

    public function invokeMethod(&$object, $methodName, array $parameters = array())
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    public function tearDown(): void
    {
        try {
            $this->rsmq->deleteQueue('foo');
        } catch (Exception $_) {

        }

    }
}