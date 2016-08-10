<?php
namespace PNixx\DelayedJob;

use League\CLImate\Util\Writer\File;

class Logger {

	const TYPE_DEBUG = 'debug';
	const TYPE_INFO = 'info';
	const TYPE_ERROR = 'error';

	/**
	 * @var Cli
	 */
	private $cli;

	/**
	 * @var string
	 */
	private $level;

	/**
	 * Logger constructor.
	 * @param Cli    $cli
	 * @param string $log_file
	 * @param string $level
	 */
	public function __construct(Cli $cli, $log_file, $level = self::TYPE_ERROR) {
		$this->cli = $cli;
		$this->level = $level;

		if( $cli->arguments->get('quiet') ) {
			if( file_exists($log_file) && !is_writable($log_file) || !file_exists($log_file) && !is_writable(pathinfo($log_file, PATHINFO_DIRNAME)) ) {
				$this->error('Permission denied to write file: ' . $log_file);
				exit(127);
			}

			$cli->output->add('logger', new File($log_file));
			$cli->output->defaultTo('logger');
		}
	}

	/**
	 * @param $message
	 */
	public function debug($message) {
		if( $this->level == self::TYPE_DEBUG ) {
			$this->cli->out($this->write($message, self::TYPE_DEBUG));
		}
	}

	/**
	 * @param $message
	 */
	public function info($message) {
		if( in_array($this->level, [self::TYPE_INFO, self::TYPE_DEBUG]) ) {
			$this->cli->info($this->write($message, self::TYPE_INFO));
		}
	}

	/**
	 * @param $message
	 */
	public function error($message) {
		$this->cli->error($this->write($message, self::TYPE_ERROR));
	}

	/**
	 * @param string $message
	 * @param string $type
	 * @return string
	 */
	protected function write($message, $type) {
		return sprintf('%s [%s]: %s', date('d.m.Y H:i:s'), $type, $message);
	}
}