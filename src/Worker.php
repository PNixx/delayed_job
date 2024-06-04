<?php
namespace PNixx\DelayedJob;

use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

class Worker extends \Workerman\Worker {

	const DEFAULT_QUEUE = 'default';
	const DEFAULT_MAX_PROCESS = 5;

	/**
	 * Current job for work process
	 * @var array
	 */
	protected array $job = [];

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
	 * @return void
	 */
	public function fetchJob(): void {
		if( self::$status == self::STATUS_RUNNING && count($this->job) < $this->max_processes ) {
			//Get first job data
			$data = DelayedJob::getJob($this->queue);
			if( $data ) {
				$this->runJob($data);
			}
		}
	}

	/**
	 * @return void
	 */
	public function onWorkerStopped(): void {
		$error = error_get_last();
		if( $error && $this->job ) {

			//Return to failed queue
			$this->jobFailed($this->job, $error['message']);

			//Remove from process job
			DelayedJob::removeProcess($this->queue, $this->job['id']);
		}
	}

	/**
	 * @param array $data
	 */
	protected function runJob(array $data): void {

		//Send to log
		$this->logger?->info(sprintf('Starting work on (%s | %s | %s, attempt: %d | %s)', $this->queue, $data['id'], $data['class'], $data['attempt'], json_encode($data['data'])));

		//Check existing class
		if( !class_exists($data['class']) ) {

			//Make error message
			$data['error_message'] = sprintf('Class "%s" not found', $data['class']);

			//Save job to failed list
			DelayedJob::push($this->queue, $data, DelayedJob::TYPE_FAILED);
			$this->logger?->error(sprintf('Job error: %s', $data['error_message']));
		} else {
			/** @var Job $class */
			$class = $data['class'];
			$job = new $class;
			$this->job[$data['id']] = $job;
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
			} catch (\Exception $e) {
				$this->jobFailed($data, $e::class . ': ' . $e->getMessage());
			} finally {
				//Remove job from processing
				unset($this->job[$data['id']]);
				DelayedJob::removeProcess($this->queue, $data['id']);
			}
		}
	}

	/**
	 * @param array  $data
	 * @param string $message
	 */
	protected function jobFailed(array $data, string $message): void {
		/** @var Job $class */
		$class = $data['class'];

		//Calculate run time
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
		$this->logger?->error(sprintf('Job %s error: %s', $data['id'], $data['error_message']));
	}
}
