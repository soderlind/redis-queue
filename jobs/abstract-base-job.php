<?php
/**
 * Abstract Base Job Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all queue jobs.
 *
 * @since 1.0.0
 */
abstract class Abstract_Base_Job implements Queue_Job {

	/**
	 * Job payload data.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $payload = array();

	/**
	 * Job priority (lower number = higher priority).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected $priority = 50;

	/**
	 * Maximum retry attempts.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected $retry_attempts = 3;

	/**
	 * Job timeout in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected $timeout = 300;

	/**
	 * Queue name for this job.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $queue_name = 'default';

	/**
	 * Retry backoff delays in seconds.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $retry_backoff = array( 60, 300, 900 );

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param array $payload Job payload data.
	 */
	public function __construct( $payload = array() ) {
		$this->payload = $payload;
	}

	/**
	 * Get the job type identifier.
	 *
	 * @since 1.0.0
	 * @return string Job type.
	 */
	abstract public function get_job_type();

	/**
	 * Execute the job.
	 *
	 * @since 1.0.0
	 * @return Job_Result The job execution result.
	 */
	abstract public function execute();

	/**
	 * Get the job payload data.
	 *
	 * @since 1.0.0
	 * @return array Job payload.
	 */
	public function get_payload() {
		return $this->payload;
	}

	/**
	 * Get the job priority (lower number = higher priority).
	 *
	 * @since 1.0.0
	 * @return int Priority level.
	 */
	public function get_priority() {
		return $this->priority;
	}

	/**
	 * Get the maximum number of retry attempts.
	 *
	 * @since 1.0.0
	 * @return int Max retry attempts.
	 */
	public function get_retry_attempts() {
		return $this->retry_attempts;
	}

	/**
	 * Get the job timeout in seconds.
	 *
	 * @since 1.0.0
	 * @return int Timeout in seconds.
	 */
	public function get_timeout() {
		return $this->timeout;
	}

	/**
	 * Get the queue name for this job.
	 *
	 * @since 1.0.0
	 * @return string Queue name.
	 */
	public function get_queue_name() {
		return $this->queue_name;
	}

	/**
	 * Handle job failure.
	 *
	 * NOTE: $exception may be null when the job itself returned a failure result
	 * without throwing (e.g., wp_mail returned false). Code must be null-safe.
	 *
	 * @since 1.0.0
	 * @param Exception|null $exception The exception that caused the failure (if any).
	 * @param int            $attempt   The current attempt number.
	 * @return void
	 */
	public function handle_failure( $exception, $attempt ) {
		/**
		 * Fires when a job fails.
		 *
		 * @since 1.0.0
		 * @param Abstract_Base_Job $job       Job instance.
		 * @param Exception         $exception Exception that caused the failure.
		 * @param int               $attempt   Attempt number.
		 */
		do_action( 'redis_queue_demo_job_failure', $this, $exception, $attempt );

		// Log the failure.
		if ( redis_queue_demo()->get_option( 'enable_logging', true ) ) {
			$message = $exception instanceof Exception ? $exception->getMessage() : 'No exception object (job returned failure result)';
			error_log(
				sprintf(
					'Redis Queue Demo: Job %s failed on attempt %d - %s',
					$this->get_job_type(),
					$attempt,
					$message
				)
			);
		}
	}

	/**
	 * Determine if the job should be retried after failure.
	 *
	 * @since 1.0.0
	 * @param Exception|null $exception The exception that caused the failure (if any).
	 * @param int            $attempt   The current attempt number (1-based).
	 * @return bool Whether to retry the job.
	 */
	public function should_retry( $exception, $attempt ) {
		// Don't retry if we've reached max attempts.
		if ( $attempt >= $this->retry_attempts ) {
			return false;
		}

		// If there is no exception object (logical failure), treat as retryable by default.
		if ( ! ( $exception instanceof Exception ) ) {
			return apply_filters( 'redis_queue_demo_should_retry_job', true, $this, null, $attempt );
		}

		// Don't retry for certain types of exceptions.
		$non_retryable_exceptions = array(
			'InvalidArgumentException',
			'TypeError',
			'ParseError',
		);

		$exception_class = get_class( $exception );
		if ( in_array( $exception_class, $non_retryable_exceptions, true ) ) {
			return false;
		}

		/**
		 * Filter whether a job should be retried.
		 *
		 * @since 1.0.0
		 * @param bool                $should_retry Whether to retry.
		 * @param Abstract_Base_Job   $job          Job instance.
		 * @param Exception|null      $exception    Exception that caused the failure (if any).
		 * @param int                 $attempt      Attempt number.
		 */
		return apply_filters( 'redis_queue_demo_should_retry_job', true, $this, $exception, $attempt );
	}

	/**
	 * Get retry delay in seconds for the given attempt.
	 *
	 * @since 1.0.0
	 * @param int $attempt The attempt number.
	 * @return int Delay in seconds.
	 */
	public function get_retry_delay( $attempt ) {
		$backoff_index = $attempt - 1;

		if ( isset( $this->retry_backoff[ $backoff_index ] ) ) {
			$delay = $this->retry_backoff[ $backoff_index ];
		} else {
			// Use exponential backoff if no specific delay is set.
			$delay = min( pow( 2, $attempt ) * 60, 3600 ); // Max 1 hour.
		}

		/**
		 * Filter retry delay for a job.
		 *
		 * @since 1.0.0
		 * @param int               $delay   Delay in seconds.
		 * @param Abstract_Base_Job $job     Job instance.
		 * @param int               $attempt Attempt number.
		 */
		return apply_filters( 'redis_queue_demo_job_retry_delay', $delay, $this, $attempt );
	}

	/**
	 * Serialize the job for storage.
	 *
	 * @since 1.0.0
	 * @return array Serialized job data.
	 */
	public function serialize() {
		return array(
			'class'          => get_class( $this ),
			'payload'        => $this->payload,
			'priority'       => $this->priority,
			'retry_attempts' => $this->retry_attempts,
			'timeout'        => $this->timeout,
			'queue_name'     => $this->queue_name,
			'retry_backoff'  => $this->retry_backoff,
		);
	}

	/**
	 * Deserialize job data and create job instance.
	 *
	 * @since 1.0.0
	 * @param array $data Serialized job data.
	 * @return Queue_Job Job instance.
	 */
	public static function deserialize( $data ) {
		$class = $data[ 'class' ] ?? null;

		if ( ! $class || ! class_exists( $class ) ) {
			throw new Exception( 'Invalid job class in serialized data' );
		}

		$job = new $class( $data[ 'payload' ] ?? array() );

		// Restore job properties.
		if ( isset( $data[ 'priority' ] ) ) {
			$job->priority = $data[ 'priority' ];
		}
		if ( isset( $data[ 'retry_attempts' ] ) ) {
			$job->retry_attempts = $data[ 'retry_attempts' ];
		}
		if ( isset( $data[ 'timeout' ] ) ) {
			$job->timeout = $data[ 'timeout' ];
		}
		if ( isset( $data[ 'queue_name' ] ) ) {
			$job->queue_name = $data[ 'queue_name' ];
		}
		if ( isset( $data[ 'retry_backoff' ] ) ) {
			$job->retry_backoff = $data[ 'retry_backoff' ];
		}

		return $job;
	}

	/**
	 * Set job priority.
	 *
	 * @since 1.0.0
	 * @param int $priority Priority level.
	 * @return $this
	 */
	public function set_priority( $priority ) {
		$this->priority = (int) $priority;
		return $this;
	}

	/**
	 * Set maximum retry attempts.
	 *
	 * @since 1.0.0
	 * @param int $attempts Max retry attempts.
	 * @return $this
	 */
	public function set_retry_attempts( $attempts ) {
		$this->retry_attempts = (int) $attempts;
		return $this;
	}

	/**
	 * Set job timeout.
	 *
	 * @since 1.0.0
	 * @param int $timeout Timeout in seconds.
	 * @return $this
	 */
	public function set_timeout( $timeout ) {
		$this->timeout = (int) $timeout;
		return $this;
	}

	/**
	 * Set queue name.
	 *
	 * @since 1.0.0
	 * @param string $queue_name Queue name.
	 * @return $this
	 */
	public function set_queue_name( $queue_name ) {
		$this->queue_name = sanitize_text_field( $queue_name );
		return $this;
	}

	/**
	 * Set retry backoff delays.
	 *
	 * @since 1.0.0
	 * @param array $backoff Array of delays in seconds.
	 * @return $this
	 */
	public function set_retry_backoff( $backoff ) {
		$this->retry_backoff = array_map( 'intval', $backoff );
		return $this;
	}

	/**
	 * Validate payload data.
	 *
	 * @since 1.0.0
	 * @param array $payload Payload to validate.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	protected function validate_payload( $payload ) {
		/**
		 * Filter payload validation for a job.
		 *
		 * @since 1.0.0
		 * @param bool|WP_Error     $is_valid Whether payload is valid.
		 * @param array             $payload  Payload data.
		 * @param Abstract_Base_Job $job      Job instance.
		 */
		return apply_filters( 'redis_queue_demo_validate_job_payload', true, $payload, $this );
	}

	/**
	 * Get a value from the payload with a default.
	 *
	 * @since 1.0.0
	 * @param string $key     Payload key.
	 * @param mixed  $default Default value.
	 * @return mixed Payload value or default.
	 */
	protected function get_payload_value( $key, $default = null ) {
		return $this->payload[ $key ] ?? $default;
	}

	/**
	 * Create a successful result.
	 *
	 * @since 1.0.0
	 * @param mixed $data     Result data.
	 * @param array $metadata Additional metadata.
	 * @return Basic_Job_Result
	 */
	protected function success( $data = null, $metadata = array() ) {
		return Basic_Job_Result::success( $data, $metadata );
	}

	/**
	 * Create a failed result.
	 *
	 * @since 1.0.0
	 * @param string          $error_message Error message.
	 * @param string|int|null $error_code    Error code.
	 * @param array           $metadata      Additional metadata.
	 * @return Basic_Job_Result
	 */
	protected function failure( $error_message, $error_code = null, $metadata = array() ) {
		return Basic_Job_Result::failure( $error_message, $error_code, $metadata );
	}
}