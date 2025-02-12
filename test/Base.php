<?php

namespace PNixx\DelayedJob\Test;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PNixx\DelayedJob\DelayedJob;
use PNixx\DelayedJob\Job;
use PNixx\DelayedJob\Worker;
use Workerman\Events\Fiber;
use Workerman\Timer;

class Base extends PHPUnitTestCase {

	const REDIS_QUEUE = 'test';

	protected Worker $worker;

	protected function setUp(): void {
		$redis_host = getenv('REDIS_HOST') ?: '127.0.0.1:6379';
		$ref = new \ReflectionClass(Worker::class);
		$ref->setStaticPropertyValue('status', \Workerman\Worker::STATUS_RUNNING);
		$this->worker = new Worker($redis_host, self::REDIS_QUEUE, Worker::DEFAULT_MAX_PROCESS);
		\Workerman\Worker::$globalEvent = new Fiber();
		Timer::init(\Workerman\Worker::$globalEvent);
		new DelayedJob($redis_host);
		DelayedJob::clear(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE);
		DelayedJob::clear(self::REDIS_QUEUE, DelayedJob::TYPE_FAILED);
	}

	protected function job(): Job {
		return new class extends Job {
			public static string $queue = Base::REDIS_QUEUE;
			public function perform(array $args = []): void {}
		};
	}
}
