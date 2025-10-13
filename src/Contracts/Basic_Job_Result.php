<?php
namespace Soderlind\RedisQueue\Contracts;

class Basic_Job_Result implements Job_Result {
	private bool $successful;
	private $data;
	private ?string $error_message;
	private $error_code; // string|int|null
	private array $metadata;
	private float $execution_time;
	private int $memory_usage;

	public function __construct(
		bool $successful = true,
		$data = null,
		?string $error_message = null,
		$error_code = null,
		array $metadata = [],
		float $execution_time = 0.0,
		int $memory_usage = 0
	) {
		$this->successful     = $successful;
		$this->data           = $data;
		$this->error_message  = $error_message;
		$this->error_code     = $error_code;
		$this->metadata       = $metadata;
		$this->execution_time = $execution_time;
		$this->memory_usage   = $memory_usage;
	}

	public static function success( $data = null, array $metadata = [] ): self {
		return new self( true, $data, null, null, $metadata );
	}

	public static function failure( string $error_message, $error_code = null, array $metadata = [] ): self {
		return new self( false, null, $error_message, $error_code, $metadata );
	}

	public function is_successful() {
		return $this->successful;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_error_message() {
		return $this->error_message;
	}
	public function get_error_code() {
		return $this->error_code;
	}
	public function get_metadata() {
		return $this->metadata;
	}
	public function get_execution_time() {
		return $this->execution_time;
	}
	public function get_memory_usage() {
		return $this->memory_usage;
	}

	public function to_array(): array {
		return [
			'successful'     => $this->successful,
			'data'           => $this->data,
			'error_message'  => $this->error_message,
			'error_code'     => $this->error_code,
			'metadata'       => $this->metadata,
			'execution_time' => $this->execution_time,
			'memory_usage'   => $this->memory_usage,
		];
	}

	public static function from_array( $data ): Job_Result {
		return new self(
			(bool) ( $data[ 'successful' ] ?? false ),
			$data[ 'data' ] ?? null,
			$data[ 'error_message' ] ?? null,
			$data[ 'error_code' ] ?? null,
			(array) ( $data[ 'metadata' ] ?? [] ),
			(float) ( $data[ 'execution_time' ] ?? 0.0 ),
			(int) ( $data[ 'memory_usage' ] ?? 0 )
		);
	}

	public function set_execution_time( float $time ): void {
		$this->execution_time = $time;
	}
	public function set_memory_usage( int $bytes ): void {
		$this->memory_usage = $bytes;
	}
}
