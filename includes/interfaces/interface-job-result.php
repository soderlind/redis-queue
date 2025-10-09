<?php
/**
 * Job Result Interface
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for job execution results.
 *
 * @since 1.0.0
 */
interface Job_Result {

	/**
	 * Check if the job was successful.
	 *
	 * @since 1.0.0
	 * @return bool True if successful, false otherwise.
	 */
	public function is_successful();

	/**
	 * Get the result data.
	 *
	 * @since 1.0.0
	 * @return mixed Result data.
	 */
	public function get_data();

	/**
	 * Get the error message if failed.
	 *
	 * @since 1.0.0
	 * @return string|null Error message or null if successful.
	 */
	public function get_error_message();

	/**
	 * Get the error code if failed.
	 *
	 * @since 1.0.0
	 * @return string|int|null Error code or null if successful.
	 */
	public function get_error_code();

	/**
	 * Get additional metadata about the execution.
	 *
	 * @since 1.0.0
	 * @return array Metadata array.
	 */
	public function get_metadata();

	/**
	 * Get the execution time in seconds.
	 *
	 * @since 1.0.0
	 * @return float Execution time.
	 */
	public function get_execution_time();

	/**
	 * Get the memory usage in bytes.
	 *
	 * @since 1.0.0
	 * @return int Memory usage.
	 */
	public function get_memory_usage();

	/**
	 * Convert result to array for storage.
	 *
	 * @since 1.0.0
	 * @return array Result as array.
	 */
	public function to_array();

	/**
	 * Create result from array.
	 *
	 * @since 1.0.0
	 * @param array $data Result data array.
	 * @return Job_Result Result instance.
	 */
	public static function from_array( $data );
}

/**
 * Basic job result implementation.
 *
 * @since 1.0.0
 */
class Basic_Job_Result implements Job_Result {

	/**
	 * Whether the job was successful.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $successful;

	/**
	 * Result data.
	 *
	 * @since 1.0.0
	 * @var mixed
	 */
	private $data;

	/**
	 * Error message.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private $error_message;

	/**
	 * Error code.
	 *
	 * @since 1.0.0
	 * @var string|int|null
	 */
	private $error_code;

	/**
	 * Execution metadata.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $metadata;

	/**
	 * Execution time in seconds.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $execution_time;

	/**
	 * Memory usage in bytes.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $memory_usage;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param bool             $successful     Whether the job was successful.
	 * @param mixed            $data           Result data.
	 * @param string|null      $error_message  Error message.
	 * @param string|int|null  $error_code     Error code.
	 * @param array            $metadata       Additional metadata.
	 * @param float            $execution_time Execution time.
	 * @param int              $memory_usage   Memory usage.
	 */
	public function __construct(
		$successful = true,
		$data = null,
		$error_message = null,
		$error_code = null,
		$metadata = array(),
		$execution_time = 0.0,
		$memory_usage = 0
	) {
		$this->successful     = $successful;
		$this->data           = $data;
		$this->error_message  = $error_message;
		$this->error_code     = $error_code;
		$this->metadata       = $metadata;
		$this->execution_time = $execution_time;
		$this->memory_usage   = $memory_usage;
	}

	/**
	 * Create a successful result.
	 *
	 * @since 1.0.0
	 * @param mixed $data     Result data.
	 * @param array $metadata Additional metadata.
	 * @return Basic_Job_Result
	 */
	public static function success( $data = null, $metadata = array() ) {
		return new self( true, $data, null, null, $metadata );
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
	public static function failure( $error_message, $error_code = null, $metadata = array() ) {
		return new self( false, null, $error_message, $error_code, $metadata );
	}

	/**
	 * Check if the job was successful.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_successful() {
		return $this->successful;
	}

	/**
	 * Get the result data.
	 *
	 * @since 1.0.0
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Get the error message if failed.
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_error_message() {
		return $this->error_message;
	}

	/**
	 * Get the error code if failed.
	 *
	 * @since 1.0.0
	 * @return string|int|null
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Get additional metadata about the execution.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Get the execution time in seconds.
	 *
	 * @since 1.0.0
	 * @return float
	 */
	public function get_execution_time() {
		return $this->execution_time;
	}

	/**
	 * Get the memory usage in bytes.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public function get_memory_usage() {
		return $this->memory_usage;
	}

	/**
	 * Set execution time.
	 *
	 * @since 1.0.0
	 * @param float $execution_time Execution time in seconds.
	 */
	public function set_execution_time( $execution_time ) {
		$this->execution_time = $execution_time;
	}

	/**
	 * Set memory usage.
	 *
	 * @since 1.0.0
	 * @param int $memory_usage Memory usage in bytes.
	 */
	public function set_memory_usage( $memory_usage ) {
		$this->memory_usage = $memory_usage;
	}

	/**
	 * Convert result to array for storage.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function to_array() {
		return array(
			'successful'     => $this->successful,
			'data'           => $this->data,
			'error_message'  => $this->error_message,
			'error_code'     => $this->error_code,
			'metadata'       => $this->metadata,
			'execution_time' => $this->execution_time,
			'memory_usage'   => $this->memory_usage,
		);
	}

	/**
	 * Create result from array.
	 *
	 * @since 1.0.0
	 * @param array $data Result data array.
	 * @return Basic_Job_Result
	 */
	public static function from_array( $data ) {
		return new self(
			$data[ 'successful' ] ?? true,
			$data[ 'data' ] ?? null,
			$data[ 'error_message' ] ?? null,
			$data[ 'error_code' ] ?? null,
			$data[ 'metadata' ] ?? array(),
			$data[ 'execution_time' ] ?? 0.0,
			$data[ 'memory_usage' ] ?? 0
		);
	}
}