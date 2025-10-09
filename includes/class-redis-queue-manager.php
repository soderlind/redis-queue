<?php
/**
 * Redis Queue Manager Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redis Queue Manager class.
 *
 * @since 1.0.0
 */
class Redis_Queue_Manager {

	/**
	 * Redis connection.
	 *
	 * @since 1.0.0
	 * @var Redis|Predis\Client|null
	 */
	private $redis = null;

	/**
	 * Connection status.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Queue prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $queue_prefix = 'redis_queue_demo:';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->connect();
	}

	/**
	 * Connect to Redis server.
	 *
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	private function connect() {
		try {
			$host     = redis_queue_demo()->get_option( 'redis_host', '127.0.0.1' );
			$port     = redis_queue_demo()->get_option( 'redis_port', 6379 );
			$password = redis_queue_demo()->get_option( 'redis_password', '' );
			$database = redis_queue_demo()->get_option( 'redis_database', 0 );

			// Try Redis extension first.
			if ( extension_loaded( 'redis' ) ) {
				$this->redis = new Redis();
				$connected   = $this->redis->connect( $host, $port, 2.5 );

				if ( $connected ) {
					if ( ! empty( $password ) ) {
						$this->redis->auth( $password );
					}
					$this->redis->select( $database );
					$this->connected = true;
				}
			} elseif ( class_exists( 'Predis\Client' ) ) {
				// Fallback to Predis.
				$config = array(
					'scheme'   => 'tcp',
					'host'     => $host,
					'port'     => $port,
					'database' => $database,
				);

				if ( ! empty( $password ) ) {
					$config[ 'password' ] = $password;
				}

				$this->redis = new Predis\Client( $config );
				$this->redis->connect();
				$this->connected = true;
			}

			if ( $this->connected ) {
				/**
				 * Fires after successful Redis connection.
				 *
				 * @since 1.0.0
				 * @param Redis_Queue_Manager $manager Queue manager instance.
				 */
				do_action( 'redis_queue_demo_connected', $this );
			}

			return $this->connected;

		} catch (Exception $e) {
			error_log( 'Redis Queue Demo: Connection failed - ' . $e->getMessage() );
			$this->connected = false;
			return false;
		}
	}

	/**
	 * Check if connected to Redis.
	 *
	 * @since 1.0.0
	 * @return bool True if connected, false otherwise.
	 */
	public function is_connected() {
		if ( ! $this->connected || ! $this->redis ) {
			return false;
		}

		try {
			// Test connection with a ping.
			$response = $this->redis->ping();
			return ( $response === true || $response === 'PONG' );
		} catch (Exception $e) {
			$this->connected = false;
			return false;
		}
	}

	/**
	 * Add a job to the queue.
	 *
	 * @since 1.0.0
	 * @param Queue_Job $job   Job instance.
	 * @param string    $delay Delay before processing (optional).
	 * @return string|false Job ID on success, false on failure.
	 */
	public function enqueue( Queue_Job $job, $delay = null ) {
		if ( ! $this->is_connected() ) {
			return false;
		}

		try {
			$job_id    = $this->generate_job_id();
			$job_data  = $this->prepare_job_data( $job, $job_id );
			$queue_key = $this->get_queue_key( $job->get_queue_name() );

			// Store job metadata in database.
			$this->store_job_metadata( $job_id, $job, $job_data );

			if ( $delay && $delay > 0 ) {
				// Schedule job for later processing.
				$process_time = time() + $delay;
				$delayed_key  = $this->queue_prefix . 'delayed';
				$this->redis->zadd( $delayed_key, $process_time, wp_json_encode( $job_data ) );
			} else {
				// Add to priority queue.
				$priority = $job->get_priority();
				$this->redis->zadd( $queue_key, $priority, wp_json_encode( $job_data ) );
			}

			/**
			 * Fires after a job is enqueued.
			 *
			 * @since 1.0.0
			 * @param string    $job_id Job ID.
			 * @param Queue_Job $job    Job instance.
			 */
			do_action( 'redis_queue_demo_job_enqueued', $job_id, $job );

			return $job_id;

		} catch (Exception $e) {
			error_log( 'Redis Queue Demo: Enqueue failed - ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Dequeue a job from the queue.
	 *
	 * @since 1.0.0
	 * @param string|array $queues Queue name(s) to check.
	 * @return array|null Job data or null if no jobs available.
	 */
	public function dequeue( $queues = array( 'default' ) ) {
		if ( ! $this->is_connected() ) {
			return null;
		}

		if ( is_string( $queues ) ) {
			$queues = array( $queues );
		}

		try {
			// Process delayed jobs first.
			$this->process_delayed_jobs();

			// Try each queue in order.
			foreach ( $queues as $queue_name ) {
				$queue_key = $this->get_queue_key( $queue_name );

				// Get highest priority job (lowest score).
				$jobs = $this->redis->zrange( $queue_key, 0, 0, array( 'withscores' => true ) );

				if ( ! empty( $jobs ) ) {
					$job_data = array_keys( $jobs )[ 0 ];
					$priority = array_values( $jobs )[ 0 ];

					// Remove job from queue.
					$this->redis->zrem( $queue_key, $job_data );

					$decoded_data = json_decode( $job_data, true );
					if ( $decoded_data ) {
						// Update job status to processing.
						$this->update_job_status( $decoded_data[ 'job_id' ], 'processing' );

						/**
						 * Fires after a job is dequeued.
						 *
						 * @since 1.0.0
						 * @param array $job_data Job data.
						 */
						do_action( 'redis_queue_demo_job_dequeued', $decoded_data );

						return $decoded_data;
					}
				}
			}

			return null;

		} catch (Exception $e) {
			error_log( 'Redis Queue Demo: Dequeue failed - ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Get queue statistics.
	 *
	 * @since 1.0.0
	 * @param string $queue_name Queue name (optional).
	 * @return array Queue statistics.
	 */
	public function get_queue_stats( $queue_name = null ) {
		if ( ! $this->is_connected() ) {
			return array();
		}

		try {
			$stats = array();

			if ( $queue_name ) {
				$queue_key            = $this->get_queue_key( $queue_name );
				$stats[ $queue_name ] = array(
					'pending' => $this->redis->zcard( $queue_key ),
					'size'    => $this->redis->zcard( $queue_key ),
				);
			} else {
				// Get stats for all queues.
				$pattern = $this->queue_prefix . 'queue:*';
				$keys    = $this->redis->keys( $pattern );

				foreach ( $keys as $key ) {
					$queue_name           = str_replace( $this->queue_prefix . 'queue:', '', $key );
					$stats[ $queue_name ] = array(
						'pending' => $this->redis->zcard( $key ),
						'size'    => $this->redis->zcard( $key ),
					);
				}

				// Add delayed jobs count.
				$delayed_key      = $this->queue_prefix . 'delayed';
				$stats[ 'delayed' ] = array(
					'pending' => $this->redis->zcard( $delayed_key ),
					'size'    => $this->redis->zcard( $delayed_key ),
				);
			}

			// Add database stats.
			global $wpdb;
			$table_name = $wpdb->prefix . 'redis_queue_jobs';

			$db_stats = $wpdb->get_row(
				"SELECT 
					COUNT(*) as total,
					SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
					SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
					SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
				FROM {$table_name}",
				ARRAY_A
			);

			$stats[ 'database' ] = $db_stats ?: array();

			return $stats;

		} catch (Exception $e) {
			error_log( 'Redis Queue Demo: Get stats failed - ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Clear a queue.
	 *
	 * @since 1.0.0
	 * @param string $queue_name Queue name.
	 * @return bool True on success, false on failure.
	 */
	public function clear_queue( $queue_name ) {
		if ( ! $this->is_connected() ) {
			return false;
		}

		try {
			$queue_key = $this->get_queue_key( $queue_name );
			$result    = $this->redis->del( $queue_key );

			/**
			 * Fires after a queue is cleared.
			 *
			 * @since 1.0.0
			 * @param string $queue_name Queue name.
			 */
			do_action( 'redis_queue_demo_queue_cleared', $queue_name );

			return $result > 0;

		} catch (Exception $e) {
			error_log( 'Redis Queue Demo: Clear queue failed - ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Process delayed jobs that are ready.
	 *
	 * @since 1.0.0
	 * @return int Number of jobs moved to active queues.
	 */
	private function process_delayed_jobs() {
		if ( ! $this->is_connected() ) {
			return 0;
		}

		try {
			$delayed_key  = $this->queue_prefix . 'delayed';
			$current_time = time();
			$moved_count  = 0;

			// Get jobs that are ready to process.
			$ready_jobs = $this->redis->zrangebyscore( $delayed_key, 0, $current_time );

			foreach ( $ready_jobs as $job_data ) {
				$decoded_data = json_decode( $job_data, true );
				if ( $decoded_data && isset( $decoded_data[ 'queue_name' ] ) ) {
					$queue_key = $this->get_queue_key( $decoded_data[ 'queue_name' ] );
					$priority  = $decoded_data[ 'priority' ] ?? 50;

					// Move to active queue.
					$this->redis->zadd( $queue_key, $priority, $job_data );
					$this->redis->zrem( $delayed_key, $job_data );

					$moved_count++;
				}
			}

			return $moved_count;

		} catch (Exception $e) {
			error_log( 'Redis Queue Demo: Process delayed jobs failed - ' . $e->getMessage() );
			return 0;
		}
	}

	/**
	 * Generate a unique job ID.
	 *
	 * @since 1.0.0
	 * @return string Job ID.
	 */
	private function generate_job_id() {
		return 'job_' . uniqid() . '_' . wp_rand( 1000, 9999 );
	}

	/**
	 * Prepare job data for storage.
	 *
	 * @since 1.0.0
	 * @param Queue_Job $job    Job instance.
	 * @param string    $job_id Job ID.
	 * @return array Job data.
	 */
	private function prepare_job_data( Queue_Job $job, $job_id ) {
		return array(
			'job_id'         => $job_id,
			'job_type'       => $job->get_job_type(),
			'queue_name'     => $job->get_queue_name(),
			'priority'       => $job->get_priority(),
			'payload'        => $job->get_payload(),
			'timeout'        => $job->get_timeout(),
			'max_attempts'   => $job->get_retry_attempts(),
			'created_at'     => current_time( 'mysql' ),
			'serialized_job' => $job->serialize(),
		);
	}

	/**
	 * Store job metadata in database.
	 *
	 * @since 1.0.0
	 * @param string    $job_id   Job ID.
	 * @param Queue_Job $job      Job instance.
	 * @param array     $job_data Job data.
	 * @return bool True on success, false on failure.
	 */
	private function store_job_metadata( $job_id, Queue_Job $job, $job_data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		$result = $wpdb->insert(
			$table_name,
			array(
				'job_id'       => $job_id,
				'job_type'     => $job->get_job_type(),
				'queue_name'   => $job->get_queue_name(),
				'priority'     => $job->get_priority(),
				'status'       => 'queued',
				'payload'      => wp_json_encode( $job->get_payload() ),
				'attempts'     => 0,
				'max_attempts' => $job->get_retry_attempts(),
				'timeout'      => $job->get_timeout(),
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return $result !== false;
	}

	/**
	 * Update job status in database.
	 *
	 * @since 1.0.0
	 * @param string $job_id Job ID.
	 * @param string $status New status.
	 * @return bool True on success, false on failure.
	 */
	public function update_job_status( $job_id, $status ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		$update_data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( 'processing' === $status ) {
			$update_data[ 'processed_at' ] = current_time( 'mysql' );
		} elseif ( 'failed' === $status ) {
			$update_data[ 'failed_at' ] = current_time( 'mysql' );
		}

		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'job_id' => $job_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return $result !== false;
	}

	/**
	 * Get the Redis key for a queue.
	 *
	 * @since 1.0.0
	 * @param string $queue_name Queue name.
	 * @return string Redis key.
	 */
	private function get_queue_key( $queue_name ) {
		return $this->queue_prefix . 'queue:' . $queue_name;
	}

	/**
	 * Get Redis connection for direct access.
	 *
	 * @since 1.0.0
	 * @return Redis|Predis\Client|null Redis connection.
	 */
	public function get_redis_connection() {
		return $this->redis;
	}

	/**
	 * Destructor.
	 *
	 * @since 1.0.0
	 */
	public function __destruct() {
		if ( $this->redis && $this->connected ) {
			try {
				if ( extension_loaded( 'redis' ) && $this->redis instanceof Redis ) {
					$this->redis->close();
				} elseif ( $this->redis instanceof Predis\Client ) {
					$this->redis->disconnect();
				}
			} catch (Exception $e) {
				// Ignore connection close errors.
			}
		}
	}
}