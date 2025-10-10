<?php
namespace Soderlind\RedisQueueDemo\Contracts;

interface Job_Result {
	public function is_successful();
	public function get_data();
	public function get_error_message();
	public function get_error_code();
	public function get_metadata();
	public function get_execution_time();
	public function get_memory_usage();
	public function to_array();
	public static function from_array( $data );
}
