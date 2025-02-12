<?php
namespace PNixx\DelayedJob;

abstract class Job {

	/**
	 * Queue for publishing Job
	 * @var string
	 */
	public static string $queue = Worker::DEFAULT_QUEUE;

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
	public abstract function perform(array $args = []): void;

	/**
	 * Run after success finish job
	 */
	public function completed() {}

	/**
	 * @param array    $args
	 * @param int|null $run_at
	 */
	final public static function later(array $args = [], ?int $run_at = null): void {
		DelayedJob::push(static::$queue, [
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
