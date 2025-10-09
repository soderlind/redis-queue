<?php
/**
 * Queue Job Interface
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for queue jobs.
 *
 * @since 1.0.0
 */
interface Queue_Job {

	/**
	 * Get the job type identifier.
	 *
	 * @since 1.0.0
	 * @return string Job type.
	 */
	public function get_job_type();

	/**
	 * Get the job payload data.
	 *
	 * @since 1.0.0
	 * @return array Job payload.
	 */
	public function get_payload();

	/**
	 * Get the job priority (lower number = higher priority).
	 *
	 * @since 1.0.0
	 * @return int Priority level.
	 */
	public function get_priority();

	/**
	 * Get the maximum number of retry attempts.
	 *
	 * @since 1.0.0
	 * @return int Max retry attempts.
	 */
	public function get_retry_attempts();

	/**
	 * Get the job timeout in seconds.
	 *
	 * @since 1.0.0
	 * @return int Timeout in seconds.
	 */
	public function get_timeout();

	/**
	 * Get the queue name for this job.
	 *
	 * @since 1.0.0
	 * @return string Queue name.
	 */
	public function get_queue_name();

	/**
	 * Execute the job.
	 *
	 * @since 1.0.0
	 * @return Job_Result The job execution result.
	 */
	public function execute();

	/**
	 * Handle job failure.
	 *
	 * @since 1.0.0
	 * @param Exception $exception The exception that caused the failure.
	 * @param int       $attempt   The current attempt number.
	 * @return void
	 */
	public function handle_failure( $exception, $attempt );

	/**
	 * Determine if the job should be retried after failure.
	 *
	 * @since 1.0.0
	 * @param Exception $exception The exception that caused the failure.
	 * @param int       $attempt   The current attempt number.
	 * @return bool Whether to retry the job.
	 */
	public function should_retry( $exception, $attempt );

	/**
	 * Get retry delay in seconds for the given attempt.
	 *
	 * @since 1.0.0
	 * @param int $attempt The attempt number.
	 * @return int Delay in seconds.
	 */
	public function get_retry_delay( $attempt );

	/**
	 * Serialize the job for storage.
	 *
	 * @since 1.0.0
	 * @return array Serialized job data.
	 */
	public function serialize();

	/**
	 * Deserialize job data and create job instance.
	 *
	 * @since 1.0.0
	 * @param array $data Serialized job data.
	 * @return Queue_Job Job instance.
	 */
	public static function deserialize( $data );
}