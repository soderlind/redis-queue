<?php
/**
 * Synchronous Worker Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronous worker class.
 * 
 * Processes jobs immediately when triggered via REST API or direct calls.
 *
 * @since 1.0.0
 */
class Sync_Worker {

	/**
	 * Queue manager instance.
	 *
	 * @since 1.0.0
	 * @var Redis_Queue_Manager
	 */
	private $queue_manager;

	/**
	 * Job processor instance.
	 *
	 * @since 1.0.0
	 * @var Job_Processor
	 */
	private $job_processor;

	/**
	 * Worker configuration.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $config;

	/**
	 * Worker state.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $state = 'idle';

	/**
	 * Processing statistics.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $stats = array(
		'jobs_processed' => 0,
		'jobs_failed'    => 0,
		'total_time'     => 0,
		'start_time'     => null,
		'last_activity'  => null,
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Redis_Queue_Manager $queue_manager Queue manager instance.
	 * @param Job_Processor       $job_processor Job processor instance.
	 * @param array               $config        Worker configuration.
	 */
	public function __construct( Redis_Queue_Manager $queue_manager, Job_Processor $job_processor, $config = array() ) {
		$this->queue_manager         = $queue_manager;
		$this->job_processor         = $job_processor;
		$this->config                = $this->parse_config( $config );
		$this->stats[ 'start_time' ] = microtime( true );
	}

	/**
	 * Process jobs from the queue.
	 *
	 * @since 1.0.0
	 * @param array $queues   Queue names to process.
	 * @param int   $max_jobs Maximum number of jobs to process.
	 * @return array Processing results.
	 */
	public function process_jobs( $queues = array( 'default' ), $max_jobs = null ) {
		if ( ! $this->queue_manager->is_connected() ) {
			return array(
				'success' => false,
				'error'   => 'Redis connection not available',
			);
		}

		$this->state                    = 'working';
		$this->stats[ 'last_activity' ] = microtime( true );

		// Use configured max jobs if not specified.
		if ( null === $max_jobs ) {
			$max_jobs = $this->config[ 'max_jobs_per_run' ];
		}

		/**
		 * Fires before worker starts processing jobs.
		 *
		 * @since 1.0.0
		 * @param Sync_Worker $worker   Worker instance.
		 * @param array       $queues   Queue names.
		 * @param int         $max_jobs Maximum jobs to process.
		 */
		do_action( 'redis_queue_demo_worker_start', $this, $queues, $max_jobs );

		try {
			$results = $this->job_processor->process_jobs( $queues, $max_jobs );

			// Update worker statistics.
			$this->stats[ 'jobs_processed' ] += $results[ 'processed' ];
			$this->stats[ 'total_time' ] += $results[ 'total_time' ];

			// Count failed jobs.
			foreach ( $results[ 'results' ] as $job_result ) {
				if ( ! $job_result[ 'result' ]->is_successful() ) {
					$this->stats[ 'jobs_failed' ]++;
				}
			}

			$this->state = 'idle';

			/**
			 * Fires after worker completes processing jobs.
			 *
			 * @since 1.0.0
			 * @param Sync_Worker $worker  Worker instance.
			 * @param array       $results Processing results.
			 */
			do_action( 'redis_queue_demo_worker_complete', $this, $results );

			return array(
				'success'      => true,
				'processed'    => $results[ 'processed' ],
				'total_time'   => $results[ 'total_time' ],
				'total_memory' => $results[ 'total_memory' ],
				'results'      => $results[ 'results' ],
				'worker_stats' => $this->get_stats(),
			);

		} catch (Exception $e) {
			$this->state = 'error';

			/**
			 * Fires when worker encounters an error.
			 *
			 * @since 1.0.0
			 * @param Sync_Worker $worker    Worker instance.
			 * @param Exception   $exception Exception that occurred.
			 */
			do_action( 'redis_queue_demo_worker_error', $this, $e );

			return array(
				'success' => false,
				'error'   => $e ? $e->getMessage() : 'Unknown error occurred',
				'code'    => $e ? $e->getCode() : 0,
			);

		} catch (Throwable $e) {
			$this->state = 'error';

			return array(
				'success' => false,
				'error'   => $e ? $e->getMessage() : 'Unknown throwable error occurred',
				'code'    => $e ? $e->getCode() : 0,
			);

		} finally {
			$this->stats[ 'last_activity' ] = microtime( true );
		}
	}

	/**
	 * Process a single job by ID.
	 *
	 * @since 1.0.0
	 * @param string $job_id Job ID to process.
	 * @return array Processing result.
	 */
	public function process_job_by_id( $job_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		// Get job data from database.
		$job_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE job_id = %s AND status IN ('queued', 'failed')",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $job_data ) {
			return array(
				'success' => false,
				'error'   => 'Job not found or not processable',
			);
		}

		$this->state                    = 'working';
		$this->stats[ 'last_activity' ] = microtime( true );

		try {
			// Prepare job data for processor.
			$processed_job_data = array(
				'job_id'         => $job_data[ 'job_id' ],
				'job_type'       => $job_data[ 'job_type' ],
				'queue_name'     => $job_data[ 'queue_name' ],
				'priority'       => $job_data[ 'priority' ],
				'payload'        => json_decode( $job_data[ 'payload' ], true ),
				'serialized_job' => json_decode( $job_data[ 'payload' ], true ), // Simplified for demo.
			);

			$result = $this->job_processor->process_job( $processed_job_data );

			$this->stats[ 'jobs_processed' ]++;
			$this->stats[ 'last_activity' ] = microtime( true );

			if ( ! $result->is_successful() ) {
				$this->stats[ 'jobs_failed' ]++;
			}

			$this->state = 'idle';

			return array(
				'success'      => true,
				'job_id'       => $job_id,
				'job_result'   => $result,
				'worker_stats' => $this->get_stats(),
			);

		} catch (Exception $e) {
			$this->state = 'error';
			$this->stats[ 'jobs_failed' ]++;

			return array(
				'success' => false,
				'job_id'  => $job_id,
				'error'   => $e->getMessage(),
				'code'    => $e->getCode(),
			);
		}
	}

	/**
	 * Get worker status information.
	 *
	 * @since 1.0.0
	 * @return array Worker status.
	 */
	public function get_status() {
		$uptime = microtime( true ) - $this->stats[ 'start_time' ];

		return array(
			'state'           => $this->state,
			'uptime'          => $uptime,
			'redis_connected' => $this->queue_manager->is_connected(),
			'config'          => $this->config,
			'stats'           => $this->get_stats(),
			'current_job'     => $this->job_processor->get_current_job(),
			'memory_usage'    => array(
				'current' => memory_get_usage( true ),
				'peak'    => memory_get_peak_usage( true ),
				'limit'   => $this->get_memory_limit(),
			),
		);
	}

	/**
	 * Get worker statistics.
	 *
	 * @since 1.0.0
	 * @return array Worker statistics.
	 */
	public function get_stats() {
		$uptime          = microtime( true ) - $this->stats[ 'start_time' ];
		$jobs_per_second = $uptime > 0 ? $this->stats[ 'jobs_processed' ] / $uptime : 0;

		return array_merge( $this->stats, array(
			'uptime'          => $uptime,
			'jobs_per_second' => $jobs_per_second,
			'success_rate'    => $this->calculate_success_rate(),
		) );
	}

	/**
	 * Reset worker statistics.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function reset_stats() {
		$this->stats = array(
			'jobs_processed' => 0,
			'jobs_failed'    => 0,
			'total_time'     => 0,
			'start_time'     => microtime( true ),
			'last_activity'  => null,
		);
	}

	/**
	 * Update worker configuration.
	 *
	 * @since 1.0.0
	 * @param array $config New configuration.
	 * @return void
	 */
	public function update_config( $config ) {
		$this->config = $this->parse_config( $config );
	}

	/**
	 * Get worker configuration.
	 *
	 * @since 1.0.0
	 * @return array Worker configuration.
	 */
	public function get_config() {
		return $this->config;
	}

	/**
	 * Check if worker should stop processing.
	 *
	 * @since 1.0.0
	 * @return bool True if worker should stop.
	 */
	public function should_stop() {
		// Check memory usage.
		$memory_limit  = $this->get_memory_limit();
		$current_usage = memory_get_usage( true );

		if ( $memory_limit > 0 && $current_usage > ( $memory_limit * 0.8 ) ) {
			return true;
		}

		// Check execution time.
		$uptime = microtime( true ) - $this->stats[ 'start_time' ];
		if ( $uptime > $this->config[ 'max_execution_time' ] ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse and validate worker configuration.
	 *
	 * @since 1.0.0
	 * @param array $config Raw configuration.
	 * @return array Parsed configuration.
	 */
	private function parse_config( $config ) {
		$defaults = array(
			'max_jobs_per_run'    => redis_queue_demo()->get_option( 'max_jobs_per_run', 10 ),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => redis_queue_demo()->get_option( 'worker_timeout', 300 ),
			'sleep_interval'      => 1, // Seconds between queue checks (not used in sync worker).
			'retry_failed_jobs'   => true,
			'cleanup_on_shutdown' => true,
		);

		$parsed = array_merge( $defaults, $config );

		// Validate numeric values.
		$parsed[ 'max_jobs_per_run' ]   = max( 1, (int) $parsed[ 'max_jobs_per_run' ] );
		$parsed[ 'max_execution_time' ] = max( 30, (int) $parsed[ 'max_execution_time' ] );
		$parsed[ 'sleep_interval' ]     = max( 1, (int) $parsed[ 'sleep_interval' ] );

		return $parsed;
	}

	/**
	 * Calculate success rate percentage.
	 *
	 * @since 1.0.0
	 * @return float Success rate as percentage.
	 */
	private function calculate_success_rate() {
		if ( $this->stats[ 'jobs_processed' ] === 0 ) {
			return 100.0;
		}

		$successful = $this->stats[ 'jobs_processed' ] - $this->stats[ 'jobs_failed' ];
		return ( $successful / $this->stats[ 'jobs_processed' ] ) * 100;
	}

	/**
	 * Get memory limit in bytes.
	 *
	 * @since 1.0.0
	 * @return int Memory limit in bytes, or 0 if unlimited.
	 */
	private function get_memory_limit() {
		$memory_limit = ini_get( 'memory_limit' );
		if ( '-1' === $memory_limit ) {
			return 0; // Unlimited.
		}

		$unit  = strtolower( substr( $memory_limit, -1 ) );
		$value = (int) $memory_limit;

		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}

	/**
	 * Create a worker instance with default configuration.
	 *
	 * @since 1.0.0
	 * @param Redis_Queue_Manager $queue_manager Queue manager instance.
	 * @param Job_Processor       $job_processor Job processor instance.
	 * @return Sync_Worker
	 */
	public static function create_default( Redis_Queue_Manager $queue_manager, Job_Processor $job_processor ) {
		return new self( $queue_manager, $job_processor );
	}

	/**
	 * Destructor - cleanup on shutdown.
	 *
	 * @since 1.0.0
	 */
	public function __destruct() {
		if ( $this->config[ 'cleanup_on_shutdown' ] ) {
			/**
			 * Fires when worker is shutting down.
			 *
			 * @since 1.0.0
			 * @param Sync_Worker $worker Worker instance.
			 */
			do_action( 'redis_queue_demo_worker_shutdown', $this );
		}
	}
}