<?php
namespace Soderlind\RedisQueue\Contracts;

/**
 * Queue Job Interface.
 * Defines the contract that all queue jobs must implement.
 * 
 * Jobs must be serializable for storage in Redis and the database.
 * They must also handle execution, failures, and retry logic.
 */
interface Queue_Job {
	/**
	 * Get job type identifier.
	 * 
	 * @return string Job type.
	 */
	public function get_job_type();

	/**
	 * Get job payload data.
	 * 
	 * @return array Job payload.
	 */
	public function get_payload();

	/**
	 * Get job priority.
	 * Lower values indicate higher priority.
	 * 
	 * @return int Priority value.
	 */
	public function get_priority();

	/**
	 * Get maximum retry attempts.
	 * 
	 * @return int Max retry attempts.
	 */
	public function get_retry_attempts();

	/**
	 * Get execution timeout in seconds.
	 * 
	 * @return int Timeout in seconds.
	 */
	public function get_timeout();

	/**
	 * Get queue name for this job.
	 * 
	 * @return string Queue name.
	 */
	public function get_queue_name();

	/**
	 * Execute the job.
	 * Must return a Job_Result indicating success or failure.
	 * 
	 * @return Job_Result Job execution result.
	 */
	public function execute();

	/**
	 * Handle job failure.
	 * Called when job fails to allow custom error handling and logging.
	 * 
	 * @param mixed $exception Exception or failure reason.
	 * @param int   $attempt   Current attempt number.
	 */
	public function handle_failure( $exception, $attempt );

	/**
	 * Determine if job should be retried after failure.
	 * 
	 * @param mixed $exception Exception or failure reason.
	 * @param int   $attempt   Current attempt number.
	 * @return bool True if job should be retried.
	 */
	public function should_retry( $exception, $attempt );

	/**
	 * Get delay before retry in seconds.
	 * 
	 * @param int $attempt Attempt number.
	 * @return int Delay in seconds.
	 */
	public function get_retry_delay( $attempt );

	/**
	 * Serialize job for storage.
	 * 
	 * @return array Serialized job data.
	 */
	public function serialize();

	/**
	 * Deserialize job from stored data.
	 * 
	 * @param array $data Serialized job data.
	 * @return self Job instance.
	 */
	public static function deserialize( $data );
}
