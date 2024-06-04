<?php
namespace PNixx\DelayedJob;

use League\CLImate\CLImate;

class Cli extends CLImate  {

	/**
	 * Cli constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->init();
		$this->arguments->parse();
	}

	protected function init(): void {
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
				'defaultValue' => '127.0.0.1:6379',
			],
			'save'    => [
				'longPrefix'  => 'save',
				'description' => 'Save successful jobs in the success list',
				'noValue'     => true,
			],
			'init'    => [
				'prefix'       => 'i',
				'longPrefix'  => 'init',
				'description' => 'Path to autoload.php file',
			],
			'help'      => [
				'prefix'      => 'h',
				'longPrefix'  => 'help',
				'description' => 'Prints a usage statement',
				'noValue'     => true,
			],
		]);
	}
}
