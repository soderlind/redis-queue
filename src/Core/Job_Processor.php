<?php
namespace Soderlind\RedisQueueDemo\Core;

use Exception;
use Soderlind\RedisQueueDemo\Contracts\Queue_Job;
use Soderlind\RedisQueueDemo\Contracts\Job_Result;
use Soderlind\RedisQueueDemo\Contracts\Basic_Job_Result;

/**
 * Namespaced Job Processor.
 */
class Job_Processor {
	private Redis_Queue_Manager $queue_manager;
	private ?array $current_job = null;
	private float $start_time = 0.0;
	private int $start_memory = 0;

	public function __construct( Redis_Queue_Manager $queue_manager ) {
		$this->queue_manager = $queue_manager;
	}

	public function process_job( $job_data ): Job_Result {
		$this->current_job  = $job_data;
		$this->start_time   = microtime( true );
		$this->start_memory = memory_get_usage( true );
		$job_id             = $job_data[ 'job_id' ] ?? 'unknown';
		// Early sanitize to avoid downstream empty class warnings.
		$job_data = $this->sanitize_job_data( $job_data );
		try {
			$job = $this->create_job_instance( $job_data );
			if ( ! $job ) {
				throw new Exception( 'Failed to create job instance' );
			}
			$timeout = $job->get_timeout();
			if ( $timeout > 0 ) {
				@set_time_limit( $timeout );
			}
			$result         = $job->execute();
			$execution_time = microtime( true ) - $this->start_time;
			$memory_usage   = memory_get_peak_usage( true ) - $this->start_memory;
			$result->set_execution_time( $execution_time );
			$result->set_memory_usage( $memory_usage );
			if ( $result->is_successful() ) {
				$this->handle_successful_job( $job_id, $result );
			} else {
				$this->handle_failed_job( $job_id, $job, $result, 1, null );
			}
			if ( function_exists( 'do_action' ) ) {
				\do_action( 'redis_queue_demo_job_processed', $job_id, $job, $result );
			}
			return $result;
		} catch (Exception $e) {
			$execution_time = microtime( true ) - $this->start_time;
			$memory_usage   = memory_get_peak_usage( true ) - $this->start_memory;
			$result         = Basic_Job_Result::failure( $e->getMessage(), $e->getCode(), [ 'exception_type' => get_class( $e ) ] );
			$result->set_execution_time( $execution_time );
			$result->set_memory_usage( $memory_usage );
			$job = $this->create_job_instance( $job_data );
			if ( $job ) {
				$this->handle_failed_job( $job_id, $job, $result, 1, $e );
			} else {
				$this->mark_job_failed( $job_id, $result );
			}
			if ( function_exists( 'do_action' ) ) {
				\do_action( 'redis_queue_demo_job_failed', $job_id, $e, $job_data );
			}
			return $result;
		} finally {
			$this->current_job = null;
		}
	}

	public function process_jobs( $queues = [ 'default' ], $max_jobs = 10 ): array {
		$results      = [];
		$processed    = 0;
		$start_time   = microtime( true );
		$start_memory = memory_get_usage( true );
		if ( function_exists( 'do_action' ) ) {
			\do_action( 'redis_queue_demo_batch_start', $queues, $max_jobs );
		}
		while ( $processed < $max_jobs ) {
			$job_data = $this->queue_manager->dequeue( $queues );
			if ( ! $job_data ) {
				break;
			}
			$result    = $this->process_job( $job_data );
			$results[] = [ 'job_id' => $job_data[ 'job_id' ] ?? 'unknown', 'result' => $result ];
			$processed++;
			if ( $this->should_stop_processing() ) {
				break;
			}
		}
		$total_time   = microtime( true ) - $start_time;
		$total_memory = memory_get_peak_usage( true ) - $start_memory;
		if ( function_exists( 'do_action' ) ) {
			\do_action( 'redis_queue_demo_batch_complete', $results, $processed, $total_time, $total_memory );
		}
		return [ 'processed' => $processed, 'total_time' => $total_time, 'total_memory' => $total_memory, 'results' => $results ];
	}

	private function create_job_instance( $job_data ) {
		if ( ! isset( $job_data[ 'serialized_job' ] ) ) {
			return null;
		}
		try {
			$serialized_data = $job_data[ 'serialized_job' ];
			$job_type        = $job_data[ 'job_type' ] ?? '';
			$job_class       = $this->get_job_class( $job_type );
			// Fallback: if mapping failed but serialized data has a class value, prefer that.
			if ( ( ! $job_class || ! is_string( $job_class ) ) && is_array( $serialized_data ) && ! empty( $serialized_data[ 'class' ] ) ) {
				$job_class = $serialized_data[ 'class' ];
			}
			// Normalize whitespace-only class names.
			if ( is_string( $job_class ) ) {
				$job_class = trim( $job_class );
			}
			// Guard against empty or non-string class names to avoid PHP warning: class_exists(): Class "" not found.
			if ( ! is_string( $job_class ) || $job_class === '' ) {
				\error_log( 'Redis Queue Demo: create_job_instance missing job_class. job_type=' . var_export( $job_type, true ) . ' serialized_has_class=' . ( is_array( $serialized_data ) && isset( $serialized_data[ 'class' ] ) ? 'yes' : 'no' ) . ' job_id=' . ( $job_data[ 'job_id' ] ?? 'unknown' ) . ' raw=' . substr( json_encode( $job_data ), 0, 400 ) );
				throw new Exception( $job_type === '' ? 'Empty job type supplied; cannot resolve job class' : "Unable to resolve job class for job type '{$job_type}'" );
			}
			if ( ! class_exists( $job_class ) ) {
				\error_log( 'Redis Queue Demo: job_class ' . $job_class . ' not found for job_type=' . $job_type . ' job_id=' . ( $job_data[ 'job_id' ] ?? 'unknown' ) );
				throw new Exception( "Job class '{$job_class}' (type '{$job_type}') not found/autoloadable" );
			}
			// Ensure method_exists not called with empty class (double guard) and class is loaded.
			if ( is_string( $job_class ) && $job_class !== '' && method_exists( $job_class, 'deserialize' ) ) {
				return $job_class::deserialize( $serialized_data );
			}
			throw new Exception( "Job class {$job_class} does not implement deserialize method" );
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: Failed to create job instance - ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Sanitize/infer job data before attempting instantiation.
	 * Adds serialized_job.class if missing and can be inferred. Returns original data if no change.
	 */
	private function sanitize_job_data( array $job_data ): array {
		if ( empty( $job_data[ 'serialized_job' ][ 'class' ] ?? '' ) ) {
			$job_type = $job_data[ 'job_type' ] ?? '';
			$inferred = null;
			if ( is_string( $job_type ) && $job_type !== '' ) {
				// Canonical job type mapping only (legacy variants removed).
				$map = [
					'email'            => 'Soderlind\\RedisQueueDemo\\Jobs\\Email_Job',
					'image_processing' => 'Soderlind\\RedisQueueDemo\\Jobs\\Image_Processing_Job',
					'api_sync'         => 'Soderlind\\RedisQueueDemo\\Jobs\\API_Sync_Job',
				];
				$key = $map[ strtolower( $job_type ) ] ?? null;
				if ( $key ) {
					$inferred = $key;
				} elseif ( str_contains( $job_type, '\\' ) && $this->safe_class_exists( $job_type ) ) {
					$inferred = $job_type;
				}
			}
			if ( $inferred ) {
				$job_data[ 'serialized_job' ][ 'class' ] = $inferred;
				\error_log( 'Redis Queue Demo: sanitize_job_data injected class for job_id=' . ( $job_data[ 'job_id' ] ?? 'unknown' ) . ' job_type=' . ( $job_type ?? '' ) . ' inferred=' . $inferred );
			} else {
				// If we cannot infer, log once; create_job_instance will handle failure gracefully.
				\error_log( 'Redis Queue Demo: sanitize_job_data could not infer class job_id=' . ( $job_data[ 'job_id' ] ?? 'unknown' ) . ' job_type=' . ( $job_data[ 'job_type' ] ?? '' ) );
			}
		}
		return $job_data;
	}
	private function get_job_class( $job_type ) {
		// Accept a fully qualified class name directly (namespaced) or legacy class name.
		if ( is_string( $job_type ) && str_contains( $job_type, '\\' ) ) {
			if ( $this->safe_class_exists( $job_type ) ) {
				return $job_type; // Already a class.
			}
		}
		// Normalized job type mapping (canonical identifiers).
		$job_type_normalized = strtolower( trim( $job_type ) );
		$base_map            = [
			'email'            => 'Soderlind\\RedisQueueDemo\\Jobs\\Email_Job',
			'image_processing' => 'Soderlind\\RedisQueueDemo\\Jobs\\Image_Processing_Job',
			'api_sync'         => 'Soderlind\\RedisQueueDemo\\Jobs\\API_Sync_Job',
		];
		$job_classes         = function_exists( 'apply_filters' ) ? \apply_filters( 'redis_queue_demo_job_classes', $base_map ) : $base_map;
		if ( isset( $job_classes[ $job_type_normalized ] ) ) {
			return $job_classes[ $job_type_normalized ];
		}
		return null;
	}

	/**
	 * Safe class_exists wrapper (prevents warnings for empty strings).
	 */
	private function safe_class_exists( $class ): bool {
		return is_string( $class ) && $class !== '' && class_exists( $class );
	}

	private function handle_successful_job( $job_id, Job_Result $result ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'redis_queue_jobs';
		$wpdb->update( $table, [ 'status' => 'completed', 'result' => ( function_exists( 'wp_json_encode' ) ? \wp_json_encode( $result->to_array() ) : json_encode( $result->to_array() ) ), 'updated_at' => ( function_exists( 'current_time' ) ? \current_time( 'mysql' ) : date( 'Y-m-d H:i:s' ) ) ], [ 'job_id' => $job_id ], [ '%s', '%s', '%s' ], [ '%s' ] );
		if ( function_exists( 'do_action' ) ) {
			\do_action( 'redis_queue_demo_job_completed', $job_id, $result );
		}
	}
	private function handle_failed_job( $job_id, Queue_Job $job, Job_Result $result, $attempt, $exception = null ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'redis_queue_jobs';
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, updated_at = %s WHERE job_id = %s", ( function_exists( 'current_time' ) ? \current_time( 'mysql' ) : date( 'Y-m-d H:i:s' ) ), $job_id ) );
		$info = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, max_attempts FROM {$table} WHERE job_id = %s", $job_id ) );
		if ( ! $info ) {
			return;
		}
		$current_attempts = (int) $info->attempts;
		$max_attempts     = (int) $info->max_attempts;
		if ( $current_attempts < $max_attempts && $job->should_retry( $exception, $current_attempts ) ) {
			$this->retry_job( $job_id, $job, $current_attempts );
		} else {
			$this->mark_job_failed( $job_id, $result );
			$job->handle_failure( $exception, $current_attempts );
		}
	}
	private function retry_job( $job_id, Queue_Job $job, $attempt ): void {
		$delay = $job->get_retry_delay( $attempt );
		$this->queue_manager->enqueue( $job, $delay );
		$this->queue_manager->update_job_status( $job_id, 'queued' );
		if ( function_exists( 'do_action' ) ) {
			\do_action( 'redis_queue_demo_job_retried', $job_id, $job, $attempt, $delay );
		}
	}
	private function mark_job_failed( $job_id, Job_Result $result ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'redis_queue_jobs';
		$wpdb->update( $table, [ 'status' => 'failed', 'result' => ( function_exists( 'wp_json_encode' ) ? \wp_json_encode( $result->to_array() ) : json_encode( $result->to_array() ) ), 'error_message' => $result->get_error_message(), 'failed_at' => ( function_exists( 'current_time' ) ? \current_time( 'mysql' ) : date( 'Y-m-d H:i:s' ) ), 'updated_at' => ( function_exists( 'current_time' ) ? \current_time( 'mysql' ) : date( 'Y-m-d H:i:s' ) ) ], [ 'job_id' => $job_id ], [ '%s', '%s', '%s', '%s', '%s' ], [ '%s' ] );
		if ( function_exists( 'do_action' ) ) {
			\do_action( 'redis_queue_demo_job_permanently_failed', $job_id, $result );
		}
	}
	private function should_stop_processing(): bool {
		$memory_limit = $this->get_memory_limit();
		$current      = memory_get_usage( true );
		if ( $memory_limit > 0 && $current > ( $memory_limit * 0.8 ) ) {
			return true;
		}
		$max_exec = ini_get( 'max_execution_time' );
		if ( $max_exec > 0 ) {
			$elapsed = microtime( true ) - $this->start_time;
			if ( $elapsed > ( $max_exec * 0.8 ) ) {
				return true;
			}
		}
		return false;
	}
	private function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );
		if ( $limit === '-1' )
			return 0;
		$unit  = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;
		return match ( $unit ) { 'g' => $value * 1024 * 1024 * 1024, 'm' => $value * 1024 * 1024, 'k' => $value * 1024, default => $value};
	}
	public function get_current_job() {
		return $this->current_job;
	}
}

// Legacy global class alias removed (backward compatibility dropped).
