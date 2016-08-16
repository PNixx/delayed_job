<?php
namespace PNixx\DelayedJob;

use Predis\Client;

class DelayedJob {

	//Job types
	const TYPE_QUEUE = 'queue';
	const TYPE_FAILED = 'failed';
	const TYPE_SUCCESS = 'success';
	const TYPE_PROCESSING = 'processing';

	/**
	 * @var string
	 */
	protected static $redis_server = 'tcp://localhost:6379?read_write_timeout=-1';

	/**
	 * @var Client
	 */
	protected static $redis;

	/**
	 * Reset Redis connection for new fork
	 */
	public static function resetConnection() {
		self::$redis = null;
	}

	/**
	 * @return Client
	 */
	public static function redis() {
		if( !self::$redis ) {

			//initialize redis client
			self::$redis = new Client(self::$redis_server);
		}

		return self::$redis;
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @return string
	 */
	protected static function key($queue, $type = self::TYPE_QUEUE) {
		return 'job:' . $queue . ':' . $type;
	}

	/**
	 * @param $server
	 */
	public static function setRedisServer($server) {
		self::$redis_server = $server;
	}

	/**
	 * @return string
	 */
	public static function getRedisServer() {
		return self::$redis_server;
	}

	/**
	 * @param string $queue
	 * @param array  $data
	 * @param string $type
	 * @param null   $timestamp
	 * @return bool
	 */
	public static function push($queue, array $data, $type, $timestamp = null) {

		if( !$timestamp ) {
			$timestamp = time();
		}

		if( empty($data['id']) ) {
			$data['id'] = md5(uniqid());
		}

		//save moved time
		switch($type) {
			case self::TYPE_SUCCESS:
			case self::TYPE_FAILED:
				$data['moved_at'] = $timestamp;
				break;
			case self::TYPE_QUEUE:
				$data['planning_at'] = $timestamp;
				break;
		}

		//add job
		return (bool)self::redis()->zadd(self::key($queue, $type), ...[$timestamp, json_encode($data)]);
	}

	/**
	 * @param string $queue
	 * @param array  $data
	 * @return int
	 * @throws \Exception
	 */
	public static function pushProcess($queue, array $data) {
		if( empty($data['id']) ) {
			throw new \Exception('Id process can\'t not be blank');
		}
		$data['running_at'] = time();

		return self::redis()->hset(self::key($queue, self::TYPE_PROCESSING), $data['id'], json_encode($data));
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @param int    $offset
	 * @param int    $limit
	 * @return array
	 * @throws \Exception
	 */
	public static function getQueued($queue, $type, $offset = 0, $limit = 20) {
		return array_map(function($v) {
			return json_decode($v, true);
		}, self::redis()->zrevrange(self::key($queue, $type), $offset, $limit - 1));
	}

	/**
	 * @param $queue
	 * @return array
	 */
	public static function getProcessing($queue) {
		return array_map(function($v) {
			return json_decode($v, true);
		}, self::redis()->hgetall(self::key($queue, self::TYPE_PROCESSING)));
	}

	/**
	 * Remove all objects int the list
	 * @param $queue
	 * @param $type
	 */
	public static function clear($queue, $type) {
		self::redis()->zremrangebyscore(self::key($queue, $type), '-inf', '+inf');
	}

	/**
	 * @param $queue
	 * @return array|null
	 */
	public static function getJob($queue) {
		$time = time();

		$response = self::redis()->zrangebyscore(self::key($queue), 0, $time, ['limit' => [0, 1]]);

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
	public static function remove($queue, $data, $type) {
		return self::redis()->zrem(self::key($queue, $type), $data);
	}

	/**
	 * @param $queue
	 * @param $id
	 * @return int
	 */
	public static function removeProcess($queue, $id) {
		return self::redis()->hdel(self::key($queue, self::TYPE_PROCESSING), [$id]);
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @return int|string
	 */
	public static function count($queue, $type) {
		$key = self::key($queue, $type);

		switch($type) {

			//hash
			case self::TYPE_PROCESSING:
				return self::redis()->hlen($key);

			//sorted sets
			default:
				return self::redis()->zcount($key, '-inf', '+inf');
		}
	}
}