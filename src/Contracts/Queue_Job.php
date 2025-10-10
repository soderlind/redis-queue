<?php
namespace Soderlind\RedisQueueDemo\Contracts;

interface Queue_Job {
	public function get_job_type();
	public function get_payload();
	public function get_priority();
	public function get_retry_attempts();
	public function get_timeout();
	public function get_queue_name();
	public function execute(); // Must return Job_Result
	public function handle_failure( $exception, $attempt );
	public function should_retry( $exception, $attempt );
	public function get_retry_delay( $attempt );
	public function serialize();
	public static function deserialize( $data );
}
