<?php
namespace Soderlind\RedisQueue\Jobs;

use Exception;
use Soderlind\RedisQueue\Contracts\Queue_Job;
use Soderlind\RedisQueue\Contracts\Job_Result;
use Soderlind\RedisQueue\Contracts\Basic_Job_Result;

/**
 * Abstract Base Job.
 * Base class for all queue jobs providing common functionality.
 * 
 * Child classes must implement:
 * - get_job_type(): Return job type identifier
 * - execute(): Perform job-specific work
 */
abstract class Abstract_Base_Job implements Queue_Job {
	/** @var array Job payload data. */
	protected array $payload = [];
	
	/** @var int Job priority (lower = higher priority). */
	protected int $priority = 50;
	
	/** @var int Maximum retry attempts. */
	protected int $retry_attempts = 3;
	
	/** @var int Execution timeout in seconds. */
	protected int $timeout = 300;
	
	/** @var string Queue name. */
	protected string $queue_name = 'default';
	
	/** @var array Retry backoff delays in seconds [attempt1, attempt2, attempt3]. */
	protected array $retry_backoff = [ 60, 300, 900 ];

	/**
	 * Constructor.
	 * 
	 * @param array $payload Job-specific data.
	 */
	public function __construct( $payload = [] ) {
		$this->payload = $payload;
	}

	/**
	 * Get job type identifier.
	 * 
	 * @return string Job type.
	 */
	abstract public function get_job_type();

	/**
	 * Execute the job.
	 * 
	 * @return Job_Result Job execution result.
	 */
	abstract public function execute();

	/**
	 * Get job payload.
	 * 
	 * @return array Job payload data.
	 */
	public function get_payload() {
		return $this->payload;
	}

	/**
	 * Get job priority.
	 * 
	 * @return int Priority value (lower = higher priority).
	 */
	public function get_priority() {
		return $this->priority;
	}

	/**
	 * Get maximum retry attempts.
	 * 
	 * @return int Max retry attempts.
	 */
	public function get_retry_attempts() {
		return $this->retry_attempts;
	}

	/**
	 * Get execution timeout.
	 * 
	 * @return int Timeout in seconds.
	 */
	public function get_timeout() {
		return $this->timeout;
	}

	/**
	 * Get queue name.
	 * 
	 * @return string Queue name.
	 */
	public function get_queue_name() {
		return $this->queue_name;
	}

	/**
	 * Handle job failure.
	 * Logs failure and fires action hooks.
	 * 
	 * @param mixed $exception Exception or failure reason.
	 * @param int   $attempt   Current attempt number.
	 */
	public function handle_failure( $exception, $attempt ) {
		\do_action( 'redis_queue_job_failure', $this, $exception, $attempt );
		
		// Log failure if logging is enabled.
		if ( \redis_queue()->get_option( 'enable_logging', true ) ) {
			$message = $exception instanceof Exception ? $exception->getMessage() : 'Failure result without exception';
			\error_log( sprintf( 'Redis Queue Demo: Job %s failed on attempt %d - %s', $this->get_job_type(), $attempt, $message ) );
		}
	}

	/**
	 * Determine if job should be retried after failure.
	 * 
	 * @param mixed $exception Exception or failure reason.
	 * @param int   $attempt   Current attempt number.
	 * @return bool True if job should be retried.
	 */
	public function should_retry( $exception, $attempt ) {
		// Don't retry if max attempts reached.
		if ( $attempt >= $this->retry_attempts ) {
			return false;
		}
		
		// Handle non-exception failures.
		if ( ! ( $exception instanceof Exception ) ) {
			return \apply_filters( 'redis_queue_should_retry_job', true, $this, null, $attempt );
		}
		
		// Don't retry certain exception types (programming errors).
		$non_retry = [ 'InvalidArgumentException', 'TypeError', 'ParseError' ];
		if ( in_array( get_class( $exception ), $non_retry, true ) ) {
			return false;
		}
		
		// Allow filtering of retry decision.
		return \apply_filters( 'redis_queue_should_retry_job', true, $this, $exception, $attempt );
	}

	/**
	 * Get retry delay for given attempt.
	 * Uses configured backoff or exponential backoff.
	 * 
	 * @param int $attempt Attempt number.
	 * @return int Delay in seconds before retry.
	 */
	public function get_retry_delay( $attempt ) {
		$idx   = $attempt - 1;
		// Use configured backoff or calculate exponential backoff (max 1 hour).
		$delay = $this->retry_backoff[ $idx ] ?? min( pow( 2, $attempt ) * 60, 3600 );
		return \apply_filters( 'redis_queue_job_retry_delay', $delay, $this, $attempt );
	}
	public function serialize() {
		return [ 'class' => get_class( $this ), 'payload' => $this->payload, 'priority' => $this->priority, 'retry_attempts' => $this->retry_attempts, 'timeout' => $this->timeout, 'queue_name' => $this->queue_name, 'retry_backoff' => $this->retry_backoff ];
	}
	public static function deserialize( $data ) {
		$class = $data[ 'class' ] ?? null;
		if ( ! $class || ! class_exists( $class ) ) {
			throw new Exception( 'Invalid job class in serialized data' );
		}
		$job = new $class( $data[ 'payload' ] ?? [] );
		foreach ( [ 'priority', 'retry_attempts', 'timeout', 'queue_name', 'retry_backoff' ] as $prop ) {
			if ( isset( $data[ $prop ] ) ) {
				$job->$prop = $data[ $prop ];
			}
		}
		return $job;
	}
	public function set_priority( $p ) {
		$this->priority = (int) $p;
		return $this;
	}
	public function set_retry_attempts( $a ) {
		$this->retry_attempts = (int) $a;
		return $this;
	}
	public function set_timeout( $t ) {
		$this->timeout = (int) $t;
		return $this;
	}
	public function set_queue_name( $q ) {
		$this->queue_name = \sanitize_text_field( $q );
		return $this;
	}
	public function set_retry_backoff( $b ) {
		$this->retry_backoff = array_map( 'intval', $b );
		return $this;
	}
	protected function validate_payload( $payload ) {
		return \apply_filters( 'redis_queue_validate_job_payload', true, $payload, $this );
	}
	protected function get_payload_value( $key, $default = null ) {
		return $this->payload[ $key ] ?? $default;
	}
	protected function success( $data = null, $metadata = [] ) {
		return Basic_Job_Result::success( $data, $metadata );
	}
	protected function failure( $msg, $code = null, $metadata = [] ) {
		return Basic_Job_Result::failure( $msg, $code, $metadata );
	}
}

// Legacy global class alias removed (backward compatibility dropped).
