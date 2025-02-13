<?php
namespace PNixx\DelayedJob;

use Amp\Redis\Command\Boundary\ScoreBoundary;
use Amp\Redis\Command\Option\RangeOptions;
use Amp\Redis\RedisClient;
use function Amp\Redis\createRedisClient;

final class DelayedJob {

	//Job types
	const TYPE_QUEUE = 'queue';
	const TYPE_FAILED = 'failed';
	const TYPE_SUCCESS = 'success';
	const TYPE_PROCESSING = 'processing';

	protected readonly RedisClient $client;
	protected static DelayedJob $instance;

	/**
	 * @param string $host
	 */
	public function __construct(string $host) {
		$this->client = createRedisClient('tcp://' . $host);
		self::$instance = $this;
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @return string
	 */
	public static function key(string $queue, string $type = self::TYPE_QUEUE): string {
		return 'job:' . $queue . ':' . $type;
	}

	/**
	 * @param string   $queue
	 * @param array    $data
	 * @param string   $type
	 * @param int|null $timestamp
	 */
	public static function push(string $queue, array $data, string $type, ?int $timestamp = null): void {

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
		self::$instance->client->getSortedSet(self::key($queue, $type))->add([json_encode($data, JSON_UNESCAPED_UNICODE) => $timestamp]);
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

		return self::$instance->client->getMap(self::key($queue, self::TYPE_PROCESSING))->setValue($data['id'], json_encode($data, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @param int    $offset
	 * @param int    $limit
	 * @return array
	 */
	public static function getQueued(string $queue, string $type, int $offset = 0, int $limit = 20): array {
		$options = (new RangeOptions())->withReverseOrder();
		return array_map(fn($v) => json_decode($v, true), self::$instance->client->getSortedSet(self::key($queue, $type))->getRange($offset, $limit - 1, $options));
	}

	/**
	 * @param string $queue
	 * @return array
	 */
	public static function getProcessing(string $queue): array {
		return array_map(fn($v) => json_decode($v, true), self::$instance->client->getMap(self::key($queue, self::TYPE_PROCESSING))->getAll());
	}

	/**
	 * Remove all objects int the list
	 * @param string $queue
	 * @param string $type
	 */
	public static function clear(string $queue, string $type): void {
		self::$instance->client->getSortedSet(self::key($queue, $type))->removeRangeByScore(ScoreBoundary::negativeInfinity(), ScoreBoundary::positiveInfinity());
	}

	/**
	 * @param $queue
	 * @return array|null
	 */
	public static function getJob($queue): ?array {
		$time = time();

		$options = (new RangeOptions())->withLimit(0, 1);
		$response = self::$instance->client->getSortedSet(self::key($queue))->getRangeByScore(ScoreBoundary::inclusive(0), ScoreBoundary::inclusive($time), $options);
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
		return self::$instance->client->getSortedSet(self::key($queue, $type))->remove($data);
	}

	/**
	 * @param string $queue
	 * @param string $id
	 * @return int
	 */
	public static function removeProcess(string $queue, string $id): int {
		return self::$instance->client->getMap(self::key($queue, self::TYPE_PROCESSING))->remove($id);
	}

	/**
	 * @param string $queue
	 * @param string $type
	 * @return int|string
	 */
	public static function count(string $queue, string $type): int|string {
		$key = self::key($queue, $type);

		return match ($type) {
			self::TYPE_PROCESSING => self::$instance->client->getMap($key)->getSize(),
			default => self::$instance->client->getSortedSet($key)->getSize(),
		};
	}
}
