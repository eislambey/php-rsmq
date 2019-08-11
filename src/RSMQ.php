<?php
declare(strict_types=1);

namespace Islambey\RSMQ;

use Redis;

class RSMQ
{
    const MAX_DELAY = 9999999;
    const MIN_MESSAGE_SIZE = 1024;
    const MAX_PAYLOAD_SIZE = 65536;

    /**
     * @var Redis
     */
    private $redis;

    /**
     * @var string
     */
    private $ns;

    /**
     * @var bool
     */
    private $realtime;

    /**
     * @var Util
     */
    private $util;

    /**
     * @var string
     */
    private $receiveMessageSha1;

    /**
     * @var string
     */
    private $popMessageSha1;

    /**
     * @var string
     */
    private $changeMessageVisibilitySha1;

    public function __construct(Redis $redis, string $ns = 'rsmq', bool $realtime = false)
    {
        $this->redis = $redis;
        $this->ns = "$ns:";
        $this->realtime = $realtime;

        $this->util = new Util();

        $this->initScripts();
    }

    public function createQueue(string $name, int $vt = 30, int $delay = 0, int $maxSize = 65536): bool
    {
        $this->validate([
            'queue' => $name,
            'vt' => $vt,
            'delay' => $delay,
            'maxsize' => $maxSize,
        ]);

        $key = "{$this->ns}$name:Q";

        $resp = $this->redis->time();
        $transaction = $this->redis->multi();
        $transaction->hSetNx($key, 'vt', (string)$vt);
        $transaction->hSetNx($key, 'delay', (string)$delay);
        $transaction->hSetNx($key, 'maxsize', (string)$maxSize);
        $transaction->hSetNx($key, 'created', $resp[0]);
        $transaction->hSetNx($key, 'modified', $resp[0]);
        $resp = $transaction->exec();

        if (!$resp[0]) {
            throw new Exception('Queue already exists.');
        }

        return (bool)$this->redis->sAdd("{$this->ns}QUEUES", $name);
    }

    public function listQueues()
    {
        return $this->redis->sMembers("{$this->ns}QUEUES");
    }

    public function deleteQueue(string $name): void
    {
        $this->validate([
            'queue' => $name,
        ]);

        $key = "{$this->ns}$name";
        $transaction = $this->redis->multi();
        $transaction->del("$key:Q", $key);
        $transaction->srem("{$this->ns}QUEUES", $name);
        $resp = $transaction->exec();

        if (!$resp[0]) {
            throw new Exception('Queue not found.');
        }
    }

    public function getQueueAttributes(string $queue): array
    {
        $this->validate([
            'queue' => $queue,
        ]);

        $key = "{$this->ns}$queue";
        $resp = $this->redis->time();

        $transaction = $this->redis->multi();
        $transaction->hMGet("$key:Q", ['vt', 'delay', 'maxsize', 'totalrecv', 'totalsent', 'created', 'modified']);
        $transaction->zCard($key);
        $transaction->zCount($key, $resp[0] . '0000', "+inf");
        $resp = $transaction->exec();

        if ($resp[0]['vt'] === false) {
            throw new Exception('Queue not found.');
        }

        $attributes = [
            'vt' => (int)$resp[0]['vt'],
            'delay' => (int)$resp[0]['delay'],
            'maxsize' => (int)$resp[0]['maxsize'],
            'totalrecv' => (int)$resp[0]['totalrecv'],
            'totalsent' => (int)$resp[0]['totalsent'],
            'created' => (int)$resp[0]['created'],
            'modified' => (int)$resp[0]['modified'],
            'msgs' => $resp[1],
            'hiddenmsgs' => $resp[2],
        ];

        return $attributes;
    }

    public function setQueueAttributes(string $queue, int $vt = null, int $delay = null, int $maxSize = null): array
    {
        $this->validate([
            'vt' => $vt,
            'delay' => $delay,
            'maxsize' => $maxSize,
        ]);
        $this->getQueue($queue);

        $time = $this->redis->time();
        $transaction = $this->redis->multi();

        $transaction->hSet("{$this->ns}$queue:Q", 'modified', $time[0]);
        if ($vt !== null) {
            $transaction->hSet("{$this->ns}$queue:Q", 'vt', (string)$vt);
        }

        if ($delay !== null) {
            $transaction->hSet("{$this->ns}$queue:Q", 'delay', (string)$delay);
        }

        if ($maxSize !== null) {
            $transaction->hSet("{$this->ns}$queue:Q", 'maxsize', (string)$maxSize);
        }

        $transaction->exec();

        return $this->getQueueAttributes($queue);
    }

    public function sendMessage(string $queue, string $message, array $options = []): string
    {
        $this->validate([
            'queue' => $queue,
        ]);

        $q = $this->getQueue($queue, true);
        $delay = $options['delay'] ?? $q['delay'];

        if ($q['maxsize'] !== -1 && mb_strlen($message) > $q['maxsize']) {
            throw new Exception('Message too long');
        }

        $key = "{$this->ns}$queue";

        $transaction = $this->redis->multi();
        $transaction->zadd($key, $q['ts'] + $delay * 1000, $q['uid']);
        $transaction->hset("$key:Q", $q['uid'], $message);
        $transaction->hincrby("$key:Q", 'totalsent', 1);

        if ($this->realtime) {
            $transaction->zCard($key);
        }

        $resp = $transaction->exec();

        if ($this->realtime) {
            $this->redis->publish("{$this->ns}rt:$$queue", $resp[3]);
        }

        return $q['uid'];
    }

    public function receiveMessage(string $queue, array $options = []): array
    {
        $this->validate([
            'queue' => $queue,
        ]);

        $q = $this->getQueue($queue);
        $vt = $options['vt'] ?? $q['vt'];

        $args = [
            "{$this->ns}$queue",
            $q['ts'],
            $q['ts'] + $vt * 1000
        ];
        $resp = $this->redis->evalSha($this->receiveMessageSha1, $args, 3);
        if (empty($resp)) {
            return [];
        }
        return [
            'id' => $resp[0],
            'message' => $resp[1],
            'rc' => $resp[2],
            'fr' => $resp[3],
            'sent' => base_convert(substr($resp[0], 0, 10), 36, 10) / 1000,
        ];
    }

    public function popMessage(string $queue): array
    {
        $this->validate([
            'queue' => $queue,
        ]);

        $q = $this->getQueue($queue);

        $args = [
            "{$this->ns}$queue",
            $q['ts'],
        ];
        $resp = $this->redis->evalSha($this->popMessageSha1, $args, 2);
        if (empty($resp)) {
            return [];
        }
        return [
            'id' => $resp[0],
            'message' => $resp[1],
            'rc' => $resp[2],
            'fr' => $resp[3],
            'sent' => base_convert(substr($resp[0], 0, 10), 36, 10) / 1000,
        ];
    }

    public function deleteMessage(string $queue, string $id): bool
    {
        $this->validate([
            'queue' => $queue,
            'id' => $id,
        ]);

        $key = "{$this->ns}$queue";
        $transaction = $this->redis->multi();
        $transaction->zRem($key, $id);
        $transaction->hDel("$key:Q", $id, "$id:rc", "$id:fr");
        $resp = $transaction->exec();

        return $resp[0] === 1 && $resp[1] > 0;
    }

    public function changeMessageVisibility(string $queue, string $id, int $vt): bool
    {
        $this->validate([
            'queue' => $queue,
            'id' => $id,
            'vt' => $vt,
        ]);

        $q = $this->getQueue($queue, true);

        $params = [
            "{$this->ns}$queue",
            $id,
            $q['ts'] + $vt * 1000
        ];
        $resp = $this->redis->evalSha($this->changeMessageVisibilitySha1, $params, 3);

        return (bool)$resp;
    }

    private function getQueue(string $name, bool $uid = false): array
    {
        $this->validate([
            'queue' => $name,
        ]);

        $transaction = $this->redis->multi();
        $transaction->hmget("{$this->ns}$name:Q", ['vt', 'delay', 'maxsize']);
        $transaction->time();
        $resp = $transaction->exec();

        if ($resp[0]['vt'] === false) {
            throw new Exception('Queue not found.');
        }

        $ms = $this->util->formatZeroPad((int)$resp[1][1], 6);


        $queue = [
            'vt' => (int)$resp[0]['vt'],
            'delay' => (int)$resp[0]['delay'],
            'maxsize' => (int)$resp[0]['maxsize'],
            'ts' => (int)($resp[1][0] . substr($ms, 0, 3)),
        ];

        if ($uid) {
            $uid = $this->util->makeID(22);
            $queue['uid'] = base_convert(($resp[1][0] . $ms), 10, 36) . $uid;
        }

        return $queue;
    }

    private function initScripts(): void
    {
        $receiveMessageScript = 'local msg = redis.call("ZRANGEBYSCORE", KEYS[1], "-inf", KEYS[2], "LIMIT", "0", "1")
			if #msg == 0 then
				return {}
			end
			redis.call("ZADD", KEYS[1], KEYS[3], msg[1])
			redis.call("HINCRBY", KEYS[1] .. ":Q", "totalrecv", 1)
			local mbody = redis.call("HGET", KEYS[1] .. ":Q", msg[1])
			local rc = redis.call("HINCRBY", KEYS[1] .. ":Q", msg[1] .. ":rc", 1)
			local o = {msg[1], mbody, rc}
			if rc==1 then
				redis.call("HSET", KEYS[1] .. ":Q", msg[1] .. ":fr", KEYS[2])
				table.insert(o, KEYS[2])
			else
				local fr = redis.call("HGET", KEYS[1] .. ":Q", msg[1] .. ":fr")
				table.insert(o, fr)
			end
			return o';

        $popMessageScript = 'local msg = redis.call("ZRANGEBYSCORE", KEYS[1], "-inf", KEYS[2], "LIMIT", "0", "1")
			if #msg == 0 then
				return {}
			end
			redis.call("HINCRBY", KEYS[1] .. ":Q", "totalrecv", 1)
			local mbody = redis.call("HGET", KEYS[1] .. ":Q", msg[1])
			local rc = redis.call("HINCRBY", KEYS[1] .. ":Q", msg[1] .. ":rc", 1)
			local o = {msg[1], mbody, rc}
			if rc==1 then
				table.insert(o, KEYS[2])
			else
				local fr = redis.call("HGET", KEYS[1] .. ":Q", msg[1] .. ":fr")
				table.insert(o, fr)
			end
			redis.call("ZREM", KEYS[1], msg[1])
			redis.call("HDEL", KEYS[1] .. ":Q", msg[1], msg[1] .. ":rc", msg[1] .. ":fr")
			return o';

        $changeMessageVisibilityScript = 'local msg = redis.call("ZSCORE", KEYS[1], KEYS[2])
			if not msg then
				return 0
			end
			redis.call("ZADD", KEYS[1], KEYS[3], KEYS[2])
			return 1';

        $this->receiveMessageSha1 = $this->redis->script('load', $receiveMessageScript);
        $this->popMessageSha1 = $this->redis->script('load', $popMessageScript);
        $this->changeMessageVisibilitySha1 = $this->redis->script('load', $changeMessageVisibilityScript);
    }

    public function validate(array $params): void
    {
        if (isset($params['queue']) && !preg_match('/^([a-zA-Z0-9_-]){1,160}$/', $params['queue'])) {
            throw new Exception('Invalid queue name');
        }

        if (isset($params['id']) && !preg_match('/^([a-zA-Z0-9:]){32}$/', $params['id'])) {
            throw new Exception('Invalid message id');
        }

        if (isset($params['vt']) && ($params['vt'] < 0 || $params['vt'] > self::MAX_DELAY)) {
            throw new Exception('Visibility time must be between 0 and ' . self::MAX_DELAY);
        }

        if (isset($params['delay']) && ($params['delay'] < 0 || $params['delay'] > self::MAX_DELAY)) {
            throw new Exception('Delay must be between 0 and ' . self::MAX_DELAY);
        }

        if (isset($params['maxsize']) && ($params['maxsize'] < self::MIN_MESSAGE_SIZE || $params['maxsize'] > self::MAX_PAYLOAD_SIZE)) {
            throw new Exception('Maximum message size must be between ' . self::MIN_MESSAGE_SIZE . ' and ' . self::MAX_PAYLOAD_SIZE);
        }


    }
}
