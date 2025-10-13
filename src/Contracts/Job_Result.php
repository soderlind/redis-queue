<?php
namespace Soderlind\RedisQueue\Contracts;

/**
 * Job Result Interface.
 * Defines the contract for job execution results.
 * 
 * Results track success/failure status, data, errors, and performance metrics.
 * They must be serializable for storage in the database.
 */
interface Job_Result {
	/**
	 * Check if job execution was successful.
	 * 
	 * @return bool True if successful, false if failed.
	 */
	public function is_successful();

	/**
	 * Get result data.
	 * Data returned by successful job execution.
	 * 
	 * @return mixed Result data.
	 */
	public function get_data();

	/**
	 * Get error message.
	 * Returns error message if job failed.
	 * 
	 * @return string|null Error message or null if successful.
	 */
	public function get_error_message();

	/**
	 * Get error code.
	 * Returns error code if job failed.
	 * 
	 * @return int|null Error code or null if successful.
	 */
	public function get_error_code();

	/**
	 * Get result metadata.
	 * Additional contextual information about the result.
	 * 
	 * @return array Metadata.
	 */
	public function get_metadata();

	/**
	 * Get job execution time in seconds.
	 * 
	 * @return float|null Execution time or null if not measured.
	 */
	public function get_execution_time();

	/**
	 * Get peak memory usage in bytes.
	 * 
	 * @return int|null Memory usage or null if not measured.
	 */
	public function get_memory_usage();

	/**
	 * Convert result to array for serialization.
	 * 
	 * @return array Result data as array.
	 */
	public function to_array();

	/**
	 * Create result from array data.
	 * 
	 * @param array $data Result data array.
	 * @return self Result instance.
	 */
	public static function from_array( $data );
}
