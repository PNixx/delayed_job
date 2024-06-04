<?php
namespace PNixx\DelayedJob;

abstract class Job {

	/**
	 * Attempt count used for only delayed tasks
	 * default: 0 - always repeat until it reach success
	 * @var int
	 */
	public static int $attempt = 0;

	/**
	 * Setup job before work
	 */
	public function setup() {}

	/**
	 * Working
	 * @param array $args
	 */
	public abstract function perform(array $args = []);

	/**
	 * Run after success finish job
	 */
	public function completed() {}

	/**
	 * @param string   $queue
	 * @param array    $args
	 * @param int|null $run_at
	 * @return bool
	 */
	final public static function later(string $queue, array $args = [], int $run_at = null): bool {
		return DelayedJob::push($queue, [
			'class'      => static::class,
			'attempt'    => 0,
			'created_at' => time(),
			'data'       => $args,
		], DelayedJob::TYPE_QUEUE, $run_at);
	}

	/**
	 * @param array $args
	 */
	final public static function now(array $args = []): void {
		$job = new static;
		$job->setup();
		$job->perform($args);
		$job->completed();
	}
}
