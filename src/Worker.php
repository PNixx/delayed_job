<?php
namespace PNixx\DelayedJob;

use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Workerman\Events\Fiber;
use Workerman\Redis\Client;

class Worker extends \Workerman\Worker {

	const DEFAULT_QUEUE = 'default';
	const DEFAULT_MAX_PROCESS = 5;

	/**
	 * Current job for work process
	 * @var array
	 */
	protected array $jobs = [];
	protected bool $lock = false;
	protected Client $client;

	/**
	 * Worker constructor.
	 * @param string               $redis_host    127.0.0.1:6379
	 * @param string               $queue
	 * @param int                  $max_processes Max processes for working jobs
	 * @param bool                 $save          Save successful jobs in the success list
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(protected readonly string $redis_host, protected readonly string $queue, protected readonly int $max_processes, protected readonly bool $save = false, protected ?LoggerInterface $logger = null) {
		parent::__construct();
		self::$eventLoopClass = Fiber::class;
		$this->onWorkerStart = [$this, 'onWorkerStarted'];
		$this->onWorkerStop = [$this, 'onWorkerStopped'];
	}

	/**
	 * @param LoggerInterface $logger
	 * @return void
	 */
	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
	}

	/**
	 * @return void
	 */
	public function onWorkerStarted(): void {
		$this->logger?->info('Worker started');
		new DelayedJob($this->redis_host);
		EventLoop::repeat(1, $this->fetchJob(...));
	}

	/**
	 * Receives and processes one task
	 */
	public function fetchJob(): void {
		if( self::$status == self::STATUS_RUNNING && !$this->lock ) {
			$this->lock = true;
			try {
				while( count($this->jobs) < $this->max_processes ) {

					//Get job data
					$data = DelayedJob::getJob($this->queue);
					if( !$data ) {
						break;
					}

					//Async execute
					$this->jobs[$data['id']] = null;
					EventLoop::defer(fn() => $this->runJob($data));
				}
			} finally {
				$this->lock = false;
			}
		}
	}

	/**
	 * @return void
	 */
	public function onWorkerStopped(): void {
		$error = error_get_last();
		if( $error && $this->jobs ) {

			//Return to failed queue
			foreach( $this->jobs as $job ) {
				if( is_array($job) ) {
					$this->jobFailed($job, $error['message']);
				}
			}

			//Remove from process job
			DelayedJob::removeProcess($this->queue, $this->jobs['id']);
		} else {
			while( $this->jobs ) {
				$suspension = EventLoop::getSuspension();
				EventLoop::delay(.2, fn() => $suspension->resume());
				$suspension->suspend();
			}
		}
	}

	/**
	 * @param array $data
	 */
	protected function runJob(array $data): void {

		//Send to log
		$this->logger?->info(sprintf('Starting work on (%s | %s | %s, attempt: %d | %s)', $this->queue, $data['id'], $data['class'], $data['attempt'], json_encode($data['data'])));
		try {

			//Check existing class
			if( !class_exists($data['class']) ) {

				//Make error message
				$data['error_message'] = 'Class "' . $data['class'] . '" not found';

				//Save job to failed list
				DelayedJob::push($this->queue, $data, DelayedJob::TYPE_FAILED);
				$this->logger?->error('Job error: ' . $data['error_message']);
			} else {
				/** @var Job $class */
				$class = $data['class'];
				$job = new $class;
				$this->jobs[$data['id']] = $data;
				try {
					//Save job to processing list
					DelayedJob::pushProcess($this->queue, $data);

					//Run job
					$job->now($data['data']);

					//Save to success list
					if( $this->save ) {
						DelayedJob::push($this->queue, $data, DelayedJob::TYPE_SUCCESS);
					}
					$this->logger?->info(sprintf('Job %s was completed, pid: %s.', $data['id'], getmypid()));
				} catch (\Throwable $e) {
					$this->jobFailed($data, $e::class . ': ' . $e->getMessage());
				} finally {
					//Remove job from processing
					DelayedJob::removeProcess($this->queue, $data['id']);
				}
			}
		} finally {
			unset($this->jobs[$data['id']]);
		}
	}

	/**
	 * @param array  $data
	 * @param string $message
	 */
	protected function jobFailed(array $data, string $message): void {
		/** @var Job $class */
		$class = $data['class'];
		$data['attempt']++;

		//retry attempts available
		if( $class::$attempt == 0 || $data['attempt'] < $class::$attempt ) {

			//Calculate run time
			$time = strtotime('+' . ceil(pow($data['attempt'], 1.5)) . ' SECONDS');

			//make error message
			$data['error_message'] = $message . ', retry run at ' . date('Y-m-d H:i:s', $time);

			//return job to redis
			DelayedJob::push($this->queue, $data, DelayedJob::TYPE_QUEUE, $time);
		} else {

			//make error message
			$data['error_message'] = $message . ', attempts have ended';

			//save job to failed list
			DelayedJob::push($this->queue, $data, DelayedJob::TYPE_FAILED);
		}
		$this->logger?->error(sprintf('Job %s error: %s', $data['id'], $data['error_message']));
	}
}
