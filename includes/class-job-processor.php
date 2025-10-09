<?php
/**
 * Job Processor Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Processor class.
 * 
 * Handles the execution of jobs dequeued from Redis.
 *
 * @since 1.0.0
 */
class Job_Processor {

	/**
	 * Queue manager instance.
	 *
	 * @since 1.0.0
	 * @var Redis_Queue_Manager
	 */
	private $queue_manager;

	/**
	 * Currently processing job data.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private $current_job = null;

	/**
	 * Processing start time.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $start_time;

	/**
	 * Processing start memory.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $start_memory;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Redis_Queue_Manager $queue_manager Queue manager instance.
	 */
	public function __construct( Redis_Queue_Manager $queue_manager ) {
		$this->queue_manager = $queue_manager;
	}

	/**
	 * Process a single job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data from queue.
	 * @return Job_Result Processing result.
	 */
	public function process_job( $job_data ) {
		$this->current_job  = $job_data;
		$this->start_time   = microtime( true );
		$this->start_memory = memory_get_usage( true );

		$job_id = $job_data[ 'job_id' ] ?? 'unknown';

		try {
			// Create job instance from serialized data.
			$job = $this->create_job_instance( $job_data );
			if ( ! $job ) {
				throw new Exception( 'Failed to create job instance' );
			}

			// Set timeout if specified.
			$timeout = $job->get_timeout();
			if ( $timeout > 0 ) {
				set_time_limit( $timeout );
			}

			// Execute the job.
			$result = $job->execute();

			// Calculate execution metrics.
			$execution_time = microtime( true ) - $this->start_time;
			$memory_usage   = memory_get_peak_usage( true ) - $this->start_memory;

			$result->set_execution_time( $execution_time );
			$result->set_memory_usage( $memory_usage );

			if ( $result->is_successful() ) {
				$this->handle_successful_job( $job_id, $result );
			} else {
				$this->handle_failed_job( $job_id, $job, $result, 1 );
			}

			/**
			 * Fires after a job is processed.
			 *
			 * @since 1.0.0
			 * @param string     $job_id Job ID.
			 * @param Queue_Job  $job    Job instance.
			 * @param Job_Result $result Job result.
			 */
			do_action( 'redis_queue_demo_job_processed', $job_id, $job, $result );

			return $result;

		} catch (Exception $e) {
			$execution_time = microtime( true ) - $this->start_time;
			$memory_usage   = memory_get_peak_usage( true ) - $this->start_memory;

			$result = Basic_Job_Result::failure(
				$e->getMessage(),
				$e->getCode(),
				array( 'exception_type' => get_class( $e ) )
			);

			$result->set_execution_time( $execution_time );
			$result->set_memory_usage( $memory_usage );

			// Try to get job instance for retry logic.
			$job = $this->create_job_instance( $job_data );
			if ( $job ) {
				$this->handle_failed_job( $job_id, $job, $result, 1, $e );
			} else {
				$this->mark_job_failed( $job_id, $result );
			}

			/**
			 * Fires when a job fails to process.
			 *
			 * @since 1.0.0
			 * @param string    $job_id Job ID.
			 * @param Exception $e      Exception that caused the failure.
			 * @param array     $job_data Job data.
			 */
			do_action( 'redis_queue_demo_job_failed', $job_id, $e, $job_data );

			return $result;

		} finally {
			$this->current_job = null;
		}
	}

	/**
	 * Process multiple jobs from queue.
	 *
	 * @since 1.0.0
	 * @param array $queues   Queue names to process.
	 * @param int   $max_jobs Maximum number of jobs to process.
	 * @return array Processing results.
	 */
	public function process_jobs( $queues = array( 'default' ), $max_jobs = 10 ) {
		$results      = array();
		$processed    = 0;
		$start_time   = microtime( true );
		$start_memory = memory_get_usage( true );

		/**
		 * Fires before job processing batch starts.
		 *
		 * @since 1.0.0
		 * @param array $queues   Queue names.
		 * @param int   $max_jobs Maximum jobs to process.
		 */
		do_action( 'redis_queue_demo_batch_start', $queues, $max_jobs );

		while ( $processed < $max_jobs ) {
			$job_data = $this->queue_manager->dequeue( $queues );

			if ( ! $job_data ) {
				// No more jobs available.
				break;
			}

			$result    = $this->process_job( $job_data );
			$results[] = array(
				'job_id' => $job_data[ 'job_id' ] ?? 'unknown',
				'result' => $result,
			);

			$processed++;

			// Check memory usage to prevent exhaustion.
			if ( $this->should_stop_processing() ) {
				break;
			}
		}

		$total_time   = microtime( true ) - $start_time;
		$total_memory = memory_get_peak_usage( true ) - $start_memory;

		/**
		 * Fires after job processing batch completes.
		 *
		 * @since 1.0.0
		 * @param array $results    Processing results.
		 * @param int   $processed  Number of jobs processed.
		 * @param float $total_time Total processing time.
		 * @param int   $total_memory Total memory used.
		 */
		do_action( 'redis_queue_demo_batch_complete', $results, $processed, $total_time, $total_memory );

		return array(
			'processed'    => $processed,
			'total_time'   => $total_time,
			'total_memory' => $total_memory,
			'results'      => $results,
		);
	}

	/**
	 * Create a job instance from job data.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @return Queue_Job|null Job instance or null on failure.
	 */
	private function create_job_instance( $job_data ) {
		if ( ! isset( $job_data[ 'serialized_job' ] ) ) {
			return null;
		}

		try {
			$serialized_data = $job_data[ 'serialized_job' ];
			$job_type        = $job_data[ 'job_type' ] ?? '';

			// Get job class from job type.
			$job_class = $this->get_job_class( $job_type );
			if ( ! $job_class || ! class_exists( $job_class ) ) {
				throw new Exception( "Job class not found for type: {$job_type}" );
			}

			// Create job instance using deserialize method.
			if ( method_exists( $job_class, 'deserialize' ) ) {
				return call_user_func( array( $job_class, 'deserialize' ), $serialized_data );
			}

			throw new Exception( "Job class {$job_class} does not implement deserialize method" );

		} catch (Exception $e) {
			error_log( 'Redis Queue Demo: Failed to create job instance - ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Get job class name from job type.
	 *
	 * @since 1.0.0
	 * @param string $job_type Job type.
	 * @return string|null Job class name or null if not found.
	 */
	private function get_job_class( $job_type ) {
		$job_classes = array(
			'email'            => 'Email_Job',
			'image_processing' => 'Image_Processing_Job',
			'api_sync'         => 'API_Sync_Job',
		);

		/**
		 * Filter available job classes.
		 *
		 * @since 1.0.0
		 * @param array $job_classes Job type to class mapping.
		 */
		$job_classes = apply_filters( 'redis_queue_demo_job_classes', $job_classes );

		return $job_classes[ $job_type ] ?? null;
	}

	/**
	 * Handle successful job completion.
	 *
	 * @since 1.0.0
	 * @param string     $job_id Job ID.
	 * @param Job_Result $result Job result.
	 */
	private function handle_successful_job( $job_id, Job_Result $result ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		$wpdb->update(
			$table_name,
			array(
				'status'     => 'completed',
				'result'     => wp_json_encode( $result->to_array() ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		/**
		 * Fires when a job completes successfully.
		 *
		 * @since 1.0.0
		 * @param string     $job_id Job ID.
		 * @param Job_Result $result Job result.
		 */
		do_action( 'redis_queue_demo_job_completed', $job_id, $result );
	}

	/**
	 * Handle failed job.
	 *
	 * @since 1.0.0
	 * @param string     $job_id    Job ID.
	 * @param Queue_Job  $job       Job instance.
	 * @param Job_Result $result    Job result.
	 * @param int        $attempt   Current attempt number.
	 * @param Exception  $exception Optional exception.
	 */
	private function handle_failed_job( $job_id, Queue_Job $job, Job_Result $result, $attempt, $exception = null ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		// Update attempt count.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name} SET attempts = attempts + 1, updated_at = %s WHERE job_id = %s",
				current_time( 'mysql' ),
				$job_id
			)
		);

		// Get current job info from database.
		$job_info = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT attempts, max_attempts FROM {$table_name} WHERE job_id = %s",
				$job_id
			)
		);

		if ( ! $job_info ) {
			return;
		}

		$current_attempts = (int) $job_info->attempts;
		$max_attempts     = (int) $job_info->max_attempts;

		// Check if we should retry.
		if ( $current_attempts < $max_attempts && $job->should_retry( $exception, $current_attempts ) ) {
			$this->retry_job( $job_id, $job, $current_attempts );
		} else {
			$this->mark_job_failed( $job_id, $result );
			$job->handle_failure( $exception, $current_attempts );
		}
	}

	/**
	 * Retry a failed job.
	 *
	 * @since 1.0.0
	 * @param string    $job_id  Job ID.
	 * @param Queue_Job $job     Job instance.
	 * @param int       $attempt Current attempt number.
	 */
	private function retry_job( $job_id, Queue_Job $job, $attempt ) {
		$delay = $job->get_retry_delay( $attempt );

		// Re-enqueue the job with delay.
		$this->queue_manager->enqueue( $job, $delay );

		// Update job status.
		$this->queue_manager->update_job_status( $job_id, 'queued' );

		/**
		 * Fires when a job is retried.
		 *
		 * @since 1.0.0
		 * @param string    $job_id  Job ID.
		 * @param Queue_Job $job     Job instance.
		 * @param int       $attempt Attempt number.
		 * @param int       $delay   Retry delay in seconds.
		 */
		do_action( 'redis_queue_demo_job_retried', $job_id, $job, $attempt, $delay );
	}

	/**
	 * Mark job as permanently failed.
	 *
	 * @since 1.0.0
	 * @param string     $job_id Job ID.
	 * @param Job_Result $result Job result.
	 */
	private function mark_job_failed( $job_id, Job_Result $result ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		$wpdb->update(
			$table_name,
			array(
				'status'        => 'failed',
				'result'        => wp_json_encode( $result->to_array() ),
				'error_message' => $result->get_error_message(),
				'failed_at'     => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);

		/**
		 * Fires when a job is marked as permanently failed.
		 *
		 * @since 1.0.0
		 * @param string     $job_id Job ID.
		 * @param Job_Result $result Job result.
		 */
		do_action( 'redis_queue_demo_job_permanently_failed', $job_id, $result );
	}

	/**
	 * Check if processing should stop.
	 *
	 * @since 1.0.0
	 * @return bool True if processing should stop.
	 */
	private function should_stop_processing() {
		$memory_limit  = $this->get_memory_limit();
		$current_usage = memory_get_usage( true );

		// Stop if using more than 80% of memory limit.
		if ( $memory_limit > 0 && $current_usage > ( $memory_limit * 0.8 ) ) {
			return true;
		}

		// Check for PHP timeout.
		$max_execution_time = ini_get( 'max_execution_time' );
		if ( $max_execution_time > 0 ) {
			$elapsed = microtime( true ) - $this->start_time;
			if ( $elapsed > ( $max_execution_time * 0.8 ) ) {
				return true;
			}
		}

		return false;
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
	 * Get currently processing job data.
	 *
	 * @since 1.0.0
	 * @return array|null Current job data or null if not processing.
	 */
	public function get_current_job() {
		return $this->current_job;
	}
}