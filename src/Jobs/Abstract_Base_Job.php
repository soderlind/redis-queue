<?php
namespace Soderlind\RedisQueue\Jobs;

use Exception;
use Soderlind\RedisQueue\Contracts\Queue_Job;
use Soderlind\RedisQueue\Contracts\Job_Result;
use Soderlind\RedisQueue\Contracts\Basic_Job_Result;

abstract class Abstract_Base_Job implements Queue_Job {
	protected array $payload = [];
	protected int $priority = 50;
	protected int $retry_attempts = 3;
	protected int $timeout = 300;
	protected string $queue_name = 'default';
	protected array $retry_backoff = [ 60, 300, 900 ];

	public function __construct( $payload = [] ) {
		$this->payload = $payload;
	}

	abstract public function get_job_type();
	abstract public function execute();
	public function get_payload() {
		return $this->payload;
	}
	public function get_priority() {
		return $this->priority;
	}
	public function get_retry_attempts() {
		return $this->retry_attempts;
	}
	public function get_timeout() {
		return $this->timeout;
	}
	public function get_queue_name() {
		return $this->queue_name;
	}
	public function handle_failure( $exception, $attempt ) {
		\do_action( 'redis_queue_job_failure', $this, $exception, $attempt );
		if ( \redis_queue()->get_option( 'enable_logging', true ) ) {
			$message = $exception instanceof Exception ? $exception->getMessage() : 'Failure result without exception';
			\error_log( sprintf( 'Redis Queue Demo: Job %s failed on attempt %d - %s', $this->get_job_type(), $attempt, $message ) );
		}
	}
	public function should_retry( $exception, $attempt ) {
		if ( $attempt >= $this->retry_attempts ) {
			return false;
		}
		if ( ! ( $exception instanceof Exception ) ) {
			return \apply_filters( 'redis_queue_should_retry_job', true, $this, null, $attempt );
		}
		$non_retry = [ 'InvalidArgumentException', 'TypeError', 'ParseError' ];
		if ( in_array( get_class( $exception ), $non_retry, true ) ) {
			return false;
		}
		return \apply_filters( 'redis_queue_should_retry_job', true, $this, $exception, $attempt );
	}
	public function get_retry_delay( $attempt ) {
		$idx   = $attempt - 1;
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
