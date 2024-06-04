<?php
namespace PNixx\DelayedJob;

use Workerman\Redis\Client;

class DelayedJob {

	//Job types
	const TYPE_QUEUE = 'queue';
	const TYPE_FAILED = 'failed';
	const TYPE_SUCCESS = 'success';
	const TYPE_PROCESSING = 'processing';

	protected readonly Client $redis;
	protected static DelayedJob $instance;

	/**
	 * @param string $host
	 */
	public function __construct(string $host) {
		$this->redis = new Client('redis://' . $host);
		self::$instance = $this;
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @return string
	 */
	protected static function key(string $queue, string $type = self::TYPE_QUEUE): string {
		return 'job:' . $queue . ':' . $type;
	}

	/**
	 * @param string $queue
	 * @param array  $data
	 * @param string $type
	 * @param null   $timestamp
	 * @return bool
	 */
	public static function push(string $queue, array $data, string $type, $timestamp = null): bool {

		if( !$timestamp ) {
			$timestamp = time();
		}

		if( empty($data['id']) ) {
			$data['id'] = md5(uniqid());
		}

		//Save moved time
		switch( $type ) {
			case self::TYPE_SUCCESS:
			case self::TYPE_FAILED:
				$data['moved_at'] = $timestamp;
				break;
			case self::TYPE_QUEUE:
				$data['planning_at'] = $timestamp;
				break;
		}

		//Add job
		return self::$instance->redis->zAdd(self::key($queue, $type), $timestamp, json_encode($data));
	}

	/**
	 * @param string $queue
	 * @param array  $data
	 * @return int
	 * @throws \Exception
	 */
	public static function pushProcess(string $queue, array $data): int {
		if( empty($data['id']) ) {
			throw new \Exception('Id process can\'t not be blank');
		}
		$data['running_at'] = time();

		return self::$instance->redis->hSet(self::key($queue, self::TYPE_PROCESSING), $data['id'], json_encode($data));
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @param int    $offset
	 * @param int    $limit
	 * @return array
	 */
	public static function getQueued(string $queue, string $type, int $offset = 0, int $limit = 20): array {
		return array_map(fn($v) => json_decode($v, true), self::$instance->redis->zRevRange(self::key($queue, $type), $offset, $limit - 1));
	}

	/**
	 * @param string $queue
	 * @return array
	 */
	public static function getProcessing(string $queue): array {
		return array_map(fn($v) => json_decode($v, true), self::$instance->redis->hGetAll(self::key($queue, self::TYPE_PROCESSING)));
	}

	/**
	 * Remove all objects int the list
	 * @param string $queue
	 * @param string $type
	 */
	public static function clear(string $queue, string $type): void {
		self::$instance->redis->zRemRangeByScore(self::key($queue, $type), '-inf', '+inf');
	}

	/**
	 * @param $queue
	 * @return array|null
	 */
	public static function getJob($queue): ?array {
		$time = time();

		$response = self::$instance->redis->zRangeByScore(self::key($queue), 0, $time, ['LIMIT', '0', '1']);

		if( $response ) {
			self::remove($queue, $response[0], self::TYPE_QUEUE);
			return json_decode($response[0], true);
		}

		return null;
	}

	/**
	 * @param string $queue
	 * @param string $data
	 * @param string $type
	 * @return int
	 */
	public static function remove(string $queue, string $data, string $type): int {
		return self::$instance->redis->zRem(self::key($queue, $type), $data);
	}

	/**
	 * @param string $queue
	 * @param string $id
	 * @return int
	 */
	public static function removeProcess(string $queue, string $id): int {
		return self::$instance->redis->hDel(self::key($queue, self::TYPE_PROCESSING), $id);
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @return int|string
	 */
	public static function count(string $queue, string $type): int|string {
		$key = self::key($queue, $type);

		return match ($type) {
			self::TYPE_PROCESSING => self::$instance->redis->hLen($key),
			default => self::$instance->redis->zCount($key, '-inf', '+inf'),
		};
	}
}
