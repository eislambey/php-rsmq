# Redis Simple Message Queue
[![Travis CI](https://img.shields.io/travis/eislambey/php-rsmq)](https://travis-ci.org/eislambey/php-rsmq)
[![Codecov](https://img.shields.io/codecov/c/github/eislambey/php-rsmq)](https://codecov.io/gh/eislambey/php-rsmq)

A lightweight message queue for PHP that requires no dedicated queue server. Just a Redis server.

PHP implementation of [smrchy/rsmq](https://github.com/smrchy/rsmq)

## Installation
	composer require islambey/rsmq

## Methods

### Construct
Creates a new instance of RSMQ.

Parameters:

* `$redis` (Redis): *required The Redis instance
* `$ns` (string): *optional (Default: "rsmq")* The namespace prefix used for all keys created by RSMQ
* `$realtime` (Boolean): *optional (Default: false)* Enable realtime PUBLISH of new messages

Example:

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$rsmq = new \Islambey\RSMQ\RSMQ($redis);
```

### Queue

#### createQueue
Create a new queue.

Parameters:

* `$name` (string): The Queue name. Maximum 160 characters; alphanumeric characters, hyphens (-), and underscores (_) are allowed.
* `$vt` (int): *optional* *(Default: 30)* The length of time, in seconds, that a message received from a queue will be invisible to other receiving components when they ask to receive messages. Allowed values: 0-9999999 (around 115 days)
* `$delay` (int): *optional* *(Default: 0)* The time in seconds that the delivery of all new messages in the queue will be delayed. Allowed values: 0-9999999 (around 115 days)
* `$maxsize` (int): *optional* *(Default: 65536)* The maximum message size in bytes. Allowed values: 1024-65536 and -1 (for unlimited size)

Returns:

* `true` (Bool)

Throws:
* `\Islambey\RSMQ\Exception`

Example:

```php
$rsmq->createQueue('myqueue');
```

#### listQueues
List all queues

Returns an array:

* `["qname1", "qname2"]`

Example:

```php
$queues = $rsmq->listQueues();
```

#### deleteQueue
Deletes a queue and all messages.

Parameters:

* `$name` (string): The Queue name.

Returns:

* `true` (Bool)


Throws:

* `\Islambey\RSMQ\Exception`

Example:

```php
$rsmq->deleteQueue('myqueue');
```

#### getQueueAttributes
Get queue attributes, counter and stats

Parameters:

* `$queue` (string): The Queue name.

Returns an associative array:

* `vt` (int): The visibility timeout for the queue in seconds
* `delay` (int): The delay for new messages in seconds
* `maxsize` (int): The maximum size of a message in bytes
* `totalrecv` (int): Total number of messages received from the queue
* `totalsent` (int): Total number of messages sent to the queue
* `created` (float): Timestamp (epoch in seconds) when the queue was created
* `modified` (float): Timestamp (epoch in seconds) when the queue was last modified with `setQueueAttributes`
* `msgs` (int): Current number of messages in the queue
* `hiddenmsgs` (int): Current number of hidden / not visible messages. A message can be hidden while "in flight" due to a `vt` parameter or when sent with a `delay`

Example:

```php
$attributes =  $rsmq->getQueueAttributes('myqueue');
echo "visibility timeout: ", $attributes['vt'], "\n";
echo "delay for new messages: ", $attributes['delay'], "\n";
echo "max size in bytes: ", $attributes['maxsize'], "\n";
echo "total received messages: ", $attributes['totalrecv'], "\n";
echo "total sent messages: ", $attributes['totalsent'], "\n";
echo "created: ", $attributes['created'], "\n";
echo "last modified: ", $attributes['modified'], "\n";
echo "current n of messages: ", $attributes['msgs'], "\n";
echo "hidden messages: ", $attributes['hiddenmsgs'], "\n";
```


#### setQueueAttributes
Sets queue parameters.

Parameters:

* `$queue` (string): The Queue name.
* `$vt` (int): *optional* * The length of time, in seconds, that a message received from a queue will be invisible to other receiving components when they ask to receive messages. Allowed values: 0-9999999 (around 115 days)
* `$delay` (int): *optional* The time in seconds that the delivery of all new messages in the queue will be delayed. Allowed values: 0-9999999 (around 115 days)
* `$maxsize` (int): *optional* The maximum message size in bytes. Allowed values: 1024-65536 and -1 (for unlimited size)

Note: At least one attribute (vt, delay, maxsize) must be supplied. Only attributes that are supplied will be modified.

Returns an associative array:

* `vt` (int): The visibility timeout for the queue in seconds
* `delay` (int): The delay for new messages in seconds
* `maxsize` (int): The maximum size of a message in bytes
* `totalrecv` (int): Total number of messages received from the queue
* `totalsent` (int): Total number of messages sent to the queue
* `created` (float): Timestamp (epoch in seconds) when the queue was created
* `modified` (float): Timestamp (epoch in seconds) when the queue was last modified with `setQueueAttributes`
* `msgs` (int): Current number of messages in the queue
* `hiddenmsgs` (int): Current number of hidden / not visible messages. A message can be hidden while "in flight" due to a `vt` parameter or when sent with a `delay`

Throws:
* `\Islambey\RSMQ\Exception`

Example:

```php
$queue = 'myqueue';
$vt = 50;
$delay = 10;
$maxsize = 2048;
$rsmq->setQueueAttributes($queue, $vt, $delay, $maxsize)
```

### Messages

#### sendMessage
Sends a new message.

Parameters:

* `$queue` (string)
* `$message` (string)
* `$delay` (int): *optional* *(Default: queue settings)* The time in seconds that the delivery of the message will be delayed. Allowed values: 0-9999999 (around 115 days)

Returns:

* `$id` (string): The internal message id.

Throws:
* `\Islambey\RSMQ\Exception`

Example:

```php
$id = $rsmq->sendMessage('myqueue', 'a message');
echo "Message Sent. ID: ", $id;
```

#### receiveMessage
Receive the next message from the queue.

Parameters:

* `$queue` (string): The Queue name.
* `$vt` (int): *optional* *(Default: queue settings)* The length of time, in seconds, that the received message will be invisible to others. Allowed values: 0-9999999 (around 115 days)

Returns an associative array:

  * `message` (string): The message's contents.
  * `id` (string): The internal message id.
  * `sent` (int): Timestamp of when this message was sent / created.
  * `fr` (int): Timestamp of when this message was first received.
  * `rc` (int): Number of times this message was received.

Note: Will return an empty array if no message is there  

Throws:
* `\Islambey\RSMQ\Exception`

Example:

```php
$message = $rsmq->receiveMessage('myqueue');
echo "Message ID: ", $message['id'];
echo "Message: ", $message['message'];
```

#### deleteMessage
Parameters:

* `$queue` (string): The Queue name.
* `$id` (string): message id to delete.

Returns:

* `true` if successful, `false` if the message was not found (bool).

Throws:
* `\Islambey\RSMQ\Exception`

Example:

```php
$id = $rsmq->sendMessage('queue', 'a message');
$rsmq->deleteMessage($id);
```

#### popMessage
Receive the next message from the queue **and delete it**.

**Important:** This method deletes the message it receives right away. There is no way to receive the message again if something goes wrong while working on the message.

Parameters:

* `$queue` (string): The Queue name.

Returns an associvative array:

  * `message` (string): The message's contents.
  * `id` (string): The internal message id.
  * `sent` (int): Timestamp of when this message was sent / created.
  * `fr` (int): Timestamp of when this message was first received.
  * `rc` (int): Number of times this message was received.

Note: Will return an empty object if no message is there

Throws:
* `\Islambey\RSMQ\Exception`

Example:

```php
$message = $rsmq->popMessage('myqueue');
echo "Message ID: ", $message['id'];
echo "Message: ", $message['message'];
```

#### changeMessageVisibility
Change the visibility timer of a single message.
The time when the message will be visible again is calculated from the current time (now) + `vt`.

Parameters:

* `qname` (string): The Queue name.
* `id` (string): The message id.
* `vt` (int): The length of time, in seconds, that this message will not be visible. Allowed values: 0-9999999 (around 115 days)

Returns: 

* `true` if successful, `false` if the message was not found (bool).

Throws:
* `\Islambey\RSMQ\Exception`

Example:

```php
$queue = 'myqueue';
$id = $rsmq->sendMessage($queue, 'a message');
if($rsmq->changeMessageVisibility($queue, $id, 60)) {
	echo "Message hidden for 60 secs";
}
```

## LICENSE
The MIT LICENSE. See [LICENSE](./LICENSE)
