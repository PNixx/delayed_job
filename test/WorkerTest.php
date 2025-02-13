<?php

namespace PNixx\DelayedJob\Test;

use PNixx\DelayedJob\DelayedJob;
use PNixx\DelayedJob\Job;
use PNixx\DelayedJob\Worker;
use Revolt\EventLoop;

class WorkerTest extends Base {

	public function testJob() {
		$this->job()::later();
		$this->job()::later();
		$this->assertCount(2, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
		$this->worker->fetchJob();
		$this->assertCount(0, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
	}

	public function testJobWithArgument() {
		$job = new class extends Job {
			public static string $queue = Base::REDIS_QUEUE;
			public static int $attempt = 1;
			public function perform(array $args = []): void {}
		};
		$job::later();
		$this->assertCount(1, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
		$this->worker->fetchJob();
		$this->assertCount(0, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
		$this->assertCount(0, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_FAILED));
	}

	public function testJobFailed() {
		$job = new class extends Job {
			public static string $queue = Base::REDIS_QUEUE;
			public static int $attempt = 1;
			public function perform(array $args = []): void {
				throw new \Exception("Job failed to execute");
			}
		};
		$job::later();
		$this->assertCount(1, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
		$this->worker->fetchJob();
		$this->assertCount(0, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
		$this->assertCount(1, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_FAILED));
	}

	public function testMaxJobProcesses() {
		$job = new class extends Job {
			public static string $queue = Base::REDIS_QUEUE;
			public static int $attempt = 1;
			public function perform(array $args = []): void {
				$suspension = EventLoop::getSuspension();
				EventLoop::delay(.01, fn() => $suspension->resume());
				$suspension->suspend();
			}
		};
		for($i = 0; $i <= Worker::DEFAULT_MAX_PROCESS; $i++) {
			$job::later();
		}
		$this->assertCount(Worker::DEFAULT_MAX_PROCESS + 1, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
		$this->worker->fetchJob();
		$suspension = EventLoop::getSuspension();
		EventLoop::delay(.1, fn() => $suspension->resume());
		$suspension->suspend();
		$this->assertCount(1, DelayedJob::getQueued(self::REDIS_QUEUE, DelayedJob::TYPE_QUEUE));
	}
}
