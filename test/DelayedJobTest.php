<?php

namespace PNixx\DelayedJob\Test;

use PNixx\DelayedJob\DelayedJob;
use Revolt\EventLoop;

class DelayedJobTest extends Base {

	public function testPush() {
		DelayedJob::push('test', ['test' => 'message'], DelayedJob::TYPE_QUEUE);
		$this->assertCount(1, DelayedJob::getQueued('test', DelayedJob::TYPE_QUEUE));
	}

	public function testJob() {
		$job = $this->job();

		$job::later();
		$this->assertCount(1, DelayedJob::getQueued($job::$queue, DelayedJob::TYPE_QUEUE));
	}

	public function testGetJob() {
		$job = $this->job();

		$job::later(['message' => 'hello'], time() + 1);
		$this->assertNull(DelayedJob::getJob($job::$queue));

		$suspension = EventLoop::getSuspension();
		EventLoop::delay(1, fn() => $suspension->resume());
		$suspension->suspend();

		$data = DelayedJob::getJob($job::$queue);
		$this->assertEquals(['message' => 'hello'], $data['data']);
	}
}
