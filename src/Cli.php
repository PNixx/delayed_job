<?php
namespace PNixx\DelayedJob;

class Cli extends \PNixx\Daemon\Cli  {

	/**
	 * Cli constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->init();
		$this->arguments->parse();
	}

	protected function init() {
		parent::init();
		$this->arguments->add([
			'queue'   => [
				'prefix'       => 'q',
				'longPrefix'   => 'queue',
				'description'  => 'Queue name',
				'defaultValue' => Worker::DEFAULT_QUEUE,
			],
			'process'   => [
				'longPrefix'   => 'process',
				'description'  => 'Max working process',
				'defaultValue' => Worker::DEFAULT_MAX_PROCESS,
			],
			'server'  => [
				'prefix'       => 's',
				'longPrefix'   => 'server',
				'description'  => 'Parameter string for connection Redis server.',
				'defaultValue' => DelayedJob::getRedisServer(),
			],
			'save'    => [
				'longPrefix'  => 'save',
				'description' => 'Save successful jobs in the success list',
				'noValue'     => true,
			],
		]);
	}
}