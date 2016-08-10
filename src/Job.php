<?php
namespace PNixx\DelayedJob;

abstract class Job {

	/**
	 * Attempt count used for only delayed tasks
	 * default: 0 - always repeat until it reach success
	 * @var int
	 */
	public static $attempt = 0;

	/**
	 * Setup job before work
	 */
	public function setup() {
	}

	/**
	 * Working
	 * @param array $args
	 */
	public abstract function perform($args = []);

	/**
	 * Run after success finish job
	 */
	public function completed() {
	}

	/**
	 * @param string $queue
	 * @param array  $args
	 * @param int    $run_at
	 * @return bool
	 */
	final public static function later($queue, $args = [], $run_at = null) {
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
	final public static function now($args = []) {
		$job = new static;
		$job->setup();
		$job->perform($args);
		$job->completed();
	}
}