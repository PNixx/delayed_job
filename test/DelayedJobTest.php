<?php

namespace PNixx\DelayedJob\Test;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PNixx\DelayedJob\DelayedJob;
use PNixx\DelayedJob\Job;
use PNixx\DelayedJob\Worker;
use Revolt\EventLoop;
use Workerman\Events\Revolt;
use Workerman\Timer;

class DelayedJobTest extends PHPUnitTestCase {

	protected Worker $worker;

	protected function setUp(): void {
		$this->worker = $this->getMockBuilder(Worker::class)->setConstructorArgs(['127.0.0.1:6379', 'test', 1])->onlyMethods(['runJob'])->getMock();
		\Workerman\Worker::$globalEvent = new Revolt();
		Timer::init(\Workerman\Worker::$globalEvent);
		new DelayedJob('127.0.0.1:6379');
		DelayedJob::clear('test', DelayedJob::TYPE_QUEUE);
	}

	public function testPush() {
		DelayedJob::push('test', ['test' => 'message'], DelayedJob::TYPE_QUEUE);
		$this->assertCount(1, DelayedJob::getQueued('test', DelayedJob::TYPE_QUEUE));
	}

	public function testJob() {
		$job = new class extends Job {
			public function perform(array $args = []) {}
		};

		$job::later('test');
		$this->assertCount(1, DelayedJob::getQueued('test', DelayedJob::TYPE_QUEUE));
	}

	public function testGetJob() {
		$job = new class extends Job {
			public function perform(array $args = []) {}
		};

		$job::later('test', ['message' => 'hello'], time() + 1);
		$this->assertNull(DelayedJob::getJob('test'));

		$suspension = EventLoop::getSuspension();
		Timer::add(1, fn() => $suspension->resume());
		$suspension->suspend();

		$data = DelayedJob::getJob('test');
		$this->assertEquals(['message' => 'hello'], $data['data']);
	}
}
