<?php
declare(ticks = 1);

namespace PNixx\DelayedJob;

class Worker {

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
	protected $stop = false;

	/**
	 * @var bool
	 */
	protected $save = false;

	/**
	 * @var string
	 */
	protected $pid_file;

	/**
	 * PIDs container for working jobs
	 * @var array
	 */
	protected $jobs_pid = [];

	/**
	 * @var array
	 */
	protected $signal_queue = [];

	/**
	 * PID master process
	 * @var int
	 */
	protected $pid;

	/**
	 * @var Cli
	 */
	protected $cli;

	/**
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Worker constructor.
	 * @param Cli $cli
	 */
	public function __construct(Cli $cli) {
		$this->pid_file = $cli->arguments->get('pid');
		$this->cli = $cli;
		$this->queue = $cli->arguments->get('queue');
		$this->max_processes = $cli->arguments->get('process');
		$this->save = $cli->arguments->get('save');

		//shutdown running process
		if( $cli->arguments->get('restart') && $this->isRunning() ) {
			posix_kill($this->pid, SIGQUIT);
			while($this->isRunning()) {
				usleep(500);
			}
		}

		//Initialize logger
		$this->logger = new Logger($cli, $cli->arguments->get('log'), $cli->arguments->get('log_level'));

		if( $this->isRunning() ) {
			$this->logger->error('Worker already running, pid: ' . $this->pid);
			exit(127);
		}

		//if quiet
		if( $cli->arguments->get('quiet') ) {
			$child_pid = pcntl_fork();
			if( $child_pid ) {
				exit;
			}
			posix_setsid();
		}

		//Bind signals
		pcntl_signal(SIGTERM, [$this, 'handler']);
		pcntl_signal(SIGINT, [$this, 'handler']);
		pcntl_signal(SIGCHLD, [$this, 'handler']);
		pcntl_signal(SIGQUIT, [$this, 'handler']);

		//Write pid
		file_put_contents($this->pid_file, getmypid());

		//include settings environment
		$include = $cli->arguments->get('include');
		if( $include ) {
			if( !file_exists($include) ) {
				$this->logger->error('Include file ' . $include . ' not found');
				exit(127);
			}
			require_once $cli->arguments->get('include');
		}

		set_error_handler($this->errorHandler());
		set_exception_handler($this->exceptionHandler());
	}

	/**
	 * @return \Closure
	 */
	public function errorHandler() {
		return function ($type, $message, $file, $line) {

			switch( $type ) {
				case E_USER_ERROR:
					$type = 'Fatal Error';
					break;
				case E_USER_WARNING:
				case E_WARNING:
					$type = 'Warning';
					break;
				case E_USER_NOTICE:
				case E_NOTICE:
					$type = 'Notice';
					break;
				default:
					$type = 'Unknown Error';
					break;
			}

			//get last error
			throw new \Exception(sprintf('%s: %s in %s:%d', $type, $message, $file, $line));
		};
	}

	/**
	 * @return \Closure
	 */
	public function exceptionHandler() {
		return function (\Exception $e) {
			$this->exception($e);
		};
	}

	/**
	 * @param \Exception $e
	 */
	public function exception(\Exception $e) {
		$this->logger->error(sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
		if( $this->cli->arguments->get('log_level') == Logger::TYPE_DEBUG ) {
			$this->logger->error($e->getTraceAsString());
		}
	}

	/**
	 * @return bool
	 */
	public function isRunning() {

		if( is_file($this->pid_file) ) {

			//get saved pid and check process
			$this->pid = file_get_contents($this->pid_file);
			if( posix_kill($this->pid, 0) ) {
				return true;
			}

			//remove incorrect pid file
			if( !unlink($this->pid_file) ) {
				exit(127);
			}
		}

		return false;
	}

	/**
	 * @param int  $signal
	 */
	public function handler($signal) {
		switch( $signal ) {

			case SIGQUIT:
				$this->stop = true;
				if( getmypid() == file_get_contents($this->pid_file) ) {
					$this->logger->debug('Signal exit, waiting finish jobs...');
				}
				break;

			case SIGTERM:
			case SIGINT:
				$this->stop = true;
				if( getmypid() == file_get_contents($this->pid_file) ) {
					$this->logger->debug('Kill all working jobs...' . getmypid());
					foreach( array_merge($this->jobs_pid, array_keys($this->signal_queue)) as $pid ) {
						posix_kill($pid, SIGTERM);
					}
					pcntl_wait($status);
				}
				exit;
				break;

			case SIGCHLD:
				$this->childHandler();
				break;
		}
	}

	/**
	 * @param null $pid
	 * @param null $status
	 * @return bool
	 */
	public function childHandler($pid = null, $status = null) {

		//If no pid is provided, that means we're getting the signal from the system.
		if( !$pid ) {
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}

		//Make sure we get all of the exited children
		while( $pid > 0 ) {
			if( $pid && array_search($pid, $this->jobs_pid) !== false ) {
				$code = pcntl_wexitstatus($status);
				if( $code != 0 ) {
					$this->logger->debug($pid . ' exited with status ' . $code);
				}
				unset($this->jobs_pid[array_search($pid, $this->jobs_pid)]);
			} elseif( $pid ) {
				//Oh no, our job has finished before this parent process could even note that it had been launched!
				//Let's make note of it and handle it when the parent process is ready for it
				$this->logger->debug('Adding ' . $pid . ' to the signal queue...');
				$this->signal_queue[$pid] = $status;
			}
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}

		return true;
	}

	/**
	 * Run worker process
	 */
	public function run() {
		while( !$this->stop ) {
			sleep($this->sleep);

			try {
				do {

					while(count($this->jobs_pid) >= $this->max_processes && !$this->stop) {
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

		foreach($this->jobs_pid as $pid) {
			pcntl_waitpid($pid, $status);
		}
		$this->logger->debug('Bye');
	}

	/**
	 * @param $data
	 * @return bool
	 */
	protected function runJob($data) {

		//create a new job process
		$pid = pcntl_fork();
		if( $pid < 0 ) {
			$this->logger->error('Error pid');
			return false;

		} elseif( $pid ) {
			posix_setsid();

			//Parent process
			array_push($this->jobs_pid, $pid);

			if( isset($this->signal_queue[$pid]) ) {
				$this->logger->debug('Found ' . $pid . ' in the signal queue, processing it now');
				$this->childHandler($pid, $this->signal_queue[$pid]);
				unset($this->signal_queue[$pid]);
			}
		} else {
			DelayedJob::resetConnection();

			//send to log
			$this->logger->info(sprintf('Starting work on (%s | %s | %s, attempt: %d | %s)', $this->queue, $data['id'], $data['class'], $data['attempt'], json_encode($data['data'])));

			//Check existing class
			if( !class_exists($data['class']) ) {

				//save job to failed list
				DelayedJob::push($this->queue, $data, DelayedJob::TYPE_FAILED);
				$this->logger->error(sprintf('Job error: Class "%s" does not found', $data['class']));
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
					$time = strtotime('+' . (pow(++$data['attempt'], 0.5) * 60) . ' SECONDS');

					//retry attempts available
					if( $class::$attempt == 0 || $data['attempt'] < $class::$attempt ) {

						//return job to redis
						DelayedJob::push($this->queue, $data, DelayedJob::TYPE_QUEUE, $time);
						$this->logger->error(sprintf('Job %s error: %s, retry run at %s', $data['id'], $e->getMessage(), date('d.m.Y H:i:s', $time)));
					} else {
						//save job to failed list
						DelayedJob::push($this->queue, $data, DelayedJob::TYPE_FAILED);
						$this->logger->error(sprintf('Job %s error: attempts have ended', $data['id']));
					}
				} finally {
					//remove job from processing
					DelayedJob::removeProcess($this->queue, $data['id']);
				}
			}

			//close job process
			exit(0);
		}
		return true;
	}
}