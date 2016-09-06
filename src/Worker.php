<?php
declare(ticks = 1);

namespace PNixx\DelayedJob;

use PNixx\Daemon\Daemon;

class Worker extends Daemon {

	const DEFAULT_QUEUE = 'default';
	const DEFAULT_MAX_PROCESS = 5;

	/**
	 * @var string
	 */
	protected $queue;

	/**
	 * @var int
	 */
	protected $sleep = 1;

	/**
	 * Max processes for working jobs
	 * @var int
	 */
	protected $max_processes;

	/**
	 * @var bool
	 */
	protected $save = false;

	/**
	 * Current job for work process
	 * @var array
	 */
	protected $job;

	/**
	 * Worker constructor.
	 * @param Cli $cli
	 */
	public function __construct(Cli $cli) {
		parent::__construct($cli);
		$this->queue = $cli->arguments->get('queue');
		$this->max_processes = $cli->arguments->get('process');
		$this->save = $cli->arguments->get('save');
	}

	/**
	 * @return void
	 */
	public function onShutdown() {
		$error = error_get_last();
		if( $error && $this->job ) {

			//Return to failed queue
			$this->jobFailed($this->job, $error['message']);

			//Remove from process job
			DelayedJob::removeProcess($this->queue, $this->job['id']);
		}
	}

	/**
	 * Run worker process
	 */
	public function run() {
		while( !$this->stop ) {
			sleep($this->sleep);

			try {
				do {

					while(count($this->pid_children) >= $this->max_processes && !$this->stop) {
						$this->logger->debug('Maximum children allowed, waiting...');
						sleep(1);
					}

					//exit if we will stop worker
					if( $this->stop ) {
						break 2;
					}

					//get first job data
					$data = DelayedJob::getJob($this->queue);
					if( $data ) {
						$this->runJob($data);
					}
				} while( $data );
			} catch( \Exception $e ) {
				$this->exception($e);
				sleep(10);
			}
		}

		foreach($this->pid_children as $pid) {
			pcntl_waitpid($pid, $status);
		}
		$this->logger->debug('Bye');
	}

	/**
	 * @param $data
	 */
	protected function runJob($data) {

		//create a new job process
		$this->async(function() use ($data) {

			//fix redis connection for child process
			DelayedJob::resetConnection();
			$this->job = $data;

			//send to log
			$this->logger->info(sprintf('Starting work on (%s | %s | %s, attempt: %d | %s)', $this->queue, $data['id'], $data['class'], $data['attempt'], json_encode($data['data'])));

			//Check existing class
			if( !class_exists($data['class']) ) {

				//make error message
				$data['error_message'] = sprintf('Class "%s" does not found', $data['class']);

				//save job to failed list
				DelayedJob::push($this->queue, $data, DelayedJob::TYPE_FAILED);
				$this->logger->error(sprintf('Job error: %s', $data['error_message']));
			} else {
				//save job to processing list
				DelayedJob::pushProcess($this->queue, $data);

				/** @var Job $class */
				$class = $data['class'];

				try {
					/** @var Job $job */
					$job = new $class;
					$job->now($data['data']);

					//save to success list
					if( $this->save ) {
						DelayedJob::push($this->queue, $data, DelayedJob::TYPE_SUCCESS);
					}
					$this->logger->info(sprintf('Job %s was completed, pid: %s.', $data['id'], getmypid()));
				} catch( \Exception $e ) {
					$this->jobFailed($data, $e->getMessage());
				} finally {
					//remove job from processing
					DelayedJob::removeProcess($this->queue, $data['id']);
				}
			}
		});
	}

	/**
	 * @param $data
	 * @param $message
	 */
	protected function jobFailed($data, $message) {
		/** @var Job $class */
		$class = $data['class'];

		//calculate run time
		$time = strtotime('+' . round(pow(++$data['attempt'], 0.5) * 30) . ' SECONDS');

		//retry attempts available
		if( $class::$attempt == 0 || $data['attempt'] < $class::$attempt ) {

			//make error message
			$data['error_message'] = sprintf('%s, retry run at %s', $message, date('d.m.Y H:i:s', $time));

			//return job to redis
			DelayedJob::push($this->queue, $data, DelayedJob::TYPE_QUEUE, $time);
		} else {

			//make error message
			$data['error_message'] = sprintf('%s, attempts have ended', $message);

			//save job to failed list
			DelayedJob::push($this->queue, $data, DelayedJob::TYPE_FAILED);
		}
		$this->logger->error(sprintf('Job %s error: %s', $data['id'], $data['error_message']));
	}
}