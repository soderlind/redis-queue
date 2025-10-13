<?php
namespace Soderlind\RedisQueue\Workers;

use Soderlind\RedisQueue\Core\Redis_Queue_Manager;
use Soderlind\RedisQueue\Core\Job_Processor;
use Exception; // For catch blocks.
use Throwable; // PHP 7+/8+ throwable base.

/**
 * Namespaced synchronous worker.
 *
 * This is a straight namespace wrapper of the original global Sync_Worker class.
 * Logic/behavior intentionally unchanged; UI & external integrations should continue to work.
 * Legacy global alias removed (backward compatibility dropped).
 *
 * @since 1.0.0 (legacy)
 * @since 1.1.0 Namespaced
 */
class Sync_Worker {
	/** @var Redis_Queue_Manager */
	private $queue_manager;

	/** @var Job_Processor */
	private $job_processor;

	/** @var array */
	private $config;

	/** @var string */
	private $state = 'idle';

	/** @var array */
	private $stats = [
		'jobs_processed' => 0,
		'jobs_failed'    => 0,
		'total_time'     => 0,
		'start_time'     => null,
		'last_activity'  => null,
	];

	public function __construct( Redis_Queue_Manager $queue_manager, Job_Processor $job_processor, $config = [] ) {
		$this->queue_manager         = $queue_manager;
		$this->job_processor         = $job_processor;
		$this->config                = $this->parse_config( $config );
		$this->stats[ 'start_time' ] = microtime( true );
	}

	public function process_jobs( $queues = [ 'default' ], $max_jobs = null ) {
		if ( ! $this->queue_manager->is_connected() ) {
			return [ 'success' => false, 'error' => 'Redis connection not available' ];
		}
		$this->state                    = 'working';
		$this->stats[ 'last_activity' ] = microtime( true );
		if ( null === $max_jobs ) {
			$max_jobs = $this->config[ 'max_jobs_per_run' ];
		}
		function_exists( '\do_action' ) && \do_action( 'redis_queue_demo_worker_start', $this, $queues, $max_jobs );
		function_exists( '\do_action' ) && \do_action( 'redis_queue_worker_start', $this, $queues, $max_jobs );
		try {
			$results                         = $this->job_processor->process_jobs( $queues, $max_jobs );
			$this->stats[ 'jobs_processed' ] += $results[ 'processed' ];
			$this->stats[ 'total_time' ] += $results[ 'total_time' ];
			foreach ( $results[ 'results' ] as $job_result ) {
				if ( ! $job_result[ 'result' ]->is_successful() ) {
					$this->stats[ 'jobs_failed' ]++;
				}
			}
			$this->state = 'idle';
			function_exists( '\do_action' ) && \do_action( 'redis_queue_demo_worker_complete', $this, $results );
			function_exists( '\do_action' ) && \do_action( 'redis_queue_worker_complete', $this, $results );
			return [
				'success'      => true,
				'processed'    => $results[ 'processed' ],
				'total_time'   => $results[ 'total_time' ],
				'total_memory' => $results[ 'total_memory' ],
				'results'      => $results[ 'results' ],
				'worker_stats' => $this->get_stats(),
			];
		} catch (Exception $e) {
			$this->state = 'error';
			function_exists( '\do_action' ) && \do_action( 'redis_queue_demo_worker_error', $this, $e );
			function_exists( '\do_action' ) && \do_action( 'redis_queue_worker_error', $this, $e );
			return [ 'success' => false, 'error' => $e ? $e->getMessage() : 'Unknown error occurred', 'code' => $e ? $e->getCode() : 0 ];
		} catch (Throwable $e) {
			$this->state = 'error';
			return [ 'success' => false, 'error' => $e ? $e->getMessage() : 'Unknown throwable error occurred', 'code' => $e ? $e->getCode() : 0 ];
		} finally {
			$this->stats[ 'last_activity' ] = microtime( true );
		}
	}

	/**
	 * Backward compatibility wrapper.
	 * Some legacy code (REST endpoints, cron callbacks, third-party integrations) may still call $worker->process().
	 * Provide a thin adapter that delegates to process_jobs() with configured defaults.
	 *
	 * @param array|null $queues Optional queues list (defaults to ['default']).
	 * @param int|null   $max_jobs Optional maximum jobs to process; falls back to config if null.
	 * @return array Result array identical to process_jobs().
	 */
	public function process( $queues = null, $max_jobs = null ) {
		if ( null === $queues ) {
			$queues = [ 'default' ];
		}
		return $this->process_jobs( $queues, $max_jobs );
	}

	public function process_job_by_id( $job_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'redis_queue_jobs';
		$array_a    = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		$job_data   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE job_id = %s AND status IN ('queued', 'failed')", $job_id ), $array_a );
		if ( ! $job_data ) {
			return [ 'success' => false, 'error' => 'Job not found or not processable' ];
		}
		$this->state                    = 'working';
		$this->stats[ 'last_activity' ] = microtime( true );
		try {
			$payload_array = json_decode( $job_data[ 'payload' ], true );
			if ( ! is_array( $payload_array ) ) {
				$payload_array = [];
			}
			// Attempt to resolve class from job_type mapping (namespaced).
			$job_type  = $job_data[ 'job_type' ];
			$job_class = null;
			// Use Job_Processor internal mapping via reflection (avoid exposing new public API just for this).
			if ( method_exists( $this->job_processor, 'get_current_job' ) ) { // cheap existence check to ensure object ok.
				try {
					$ref = new \ReflectionClass( $this->job_processor );
					if ( $ref->hasMethod( 'get_job_class' ) ) {
						$m = $ref->getMethod( 'get_job_class' );
						$m->setAccessible( true );
						$job_class = $m->invoke( $this->job_processor, $job_type );
					}
				} catch (\Throwable $e) {
					// Silently ignore; we'll fallback below.
				}
			}
			if ( ! is_string( $job_class ) || $job_class === '' ) {
				// Fallback guess: treat job_type as class if it looks namespaced, else compose PSR-4 path.
				if ( str_contains( $job_type, '\\' ) && class_exists( $job_type ) ) {
					$job_class = $job_type;
				} else {
					$map       = [
						'email'            => 'Soderlind\\RedisQueue\\Jobs\\Email_Job',
						'image_processing' => 'Soderlind\\RedisQueue\\Jobs\\Image_Processing_Job',
						'api_sync'         => 'Soderlind\\RedisQueue\\Jobs\\API_Sync_Job',
					];
					$job_class = $map[ $job_type ] ?? null;
				}
			}
			$serialized_job     = [
				'class'     => $job_class,
				'payload'   => $payload_array,
				'options'   => $payload_array[ 'options' ] ?? [],
				'timestamp' => $job_data[ 'created_at' ] ?? null,
			];
			$processed_job_data = [
				'job_id'         => $job_data[ 'job_id' ],
				'job_type'       => $job_type,
				'queue_name'     => $job_data[ 'queue_name' ],
				'priority'       => $job_data[ 'priority' ],
				'payload'        => $payload_array,
				'serialized_job' => $serialized_job,
			];
			$result             = $this->job_processor->process_job( $processed_job_data );
			$this->stats[ 'jobs_processed' ]++;
			$this->stats[ 'last_activity' ] = microtime( true );
			if ( ! $result->is_successful() ) {
				$this->stats[ 'jobs_failed' ]++;
			}
			$this->state = 'idle';
			return [ 'success' => true, 'job_id' => $job_id, 'job_result' => $result, 'worker_stats' => $this->get_stats() ];
		} catch (Exception $e) {
			$this->state = 'error';
			$this->stats[ 'jobs_failed' ]++;
			return [ 'success' => false, 'job_id' => $job_id, 'error' => $e->getMessage(), 'code' => $e->getCode() ];
		}
	}

	public function get_status() {
		$uptime = microtime( true ) - $this->stats[ 'start_time' ];
		return [
			'state'           => $this->state,
			'uptime'          => $uptime,
			'redis_connected' => $this->queue_manager->is_connected(),
			'config'          => $this->config,
			'stats'           => $this->get_stats(),
			'current_job'     => $this->job_processor->get_current_job(),
			'memory_usage'    => [
				'current' => memory_get_usage( true ),
				'peak'    => memory_get_peak_usage( true ),
				'limit'   => $this->get_memory_limit(),
			],
		];
	}

	public function get_stats() {
		$uptime          = microtime( true ) - $this->stats[ 'start_time' ];
		$jobs_per_second = $uptime > 0 ? $this->stats[ 'jobs_processed' ] / $uptime : 0;
		return array_merge( $this->stats, [
			'uptime'          => $uptime,
			'jobs_per_second' => $jobs_per_second,
			'success_rate'    => $this->calculate_success_rate(),
		] );
	}

	public function reset_stats() {
		$this->stats = [
			'jobs_processed' => 0,
			'jobs_failed'    => 0,
			'total_time'     => 0,
			'start_time'     => microtime( true ),
			'last_activity'  => null,
		];
	}

	public function update_config( $config ) {
		$this->config = $this->parse_config( $config );
	}

	public function get_config() {
		return $this->config;
	}

	public function should_stop() {
		$memory_limit  = $this->get_memory_limit();
		$current_usage = memory_get_usage( true );
		if ( $memory_limit > 0 && $current_usage > ( $memory_limit * 0.8 ) ) {
			return true;
		}
		$uptime = microtime( true ) - $this->stats[ 'start_time' ];
		return $uptime > $this->config[ 'max_execution_time' ];
	}

	private function parse_config( $config ) {
		$defaults                       = [
			'max_jobs_per_run'    => \redis_queue()->get_option( 'max_jobs_per_run', 10 ),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => \redis_queue()->get_option( 'worker_timeout', 300 ),
			'sleep_interval'      => 1,
			'retry_failed_jobs'   => true,
			'cleanup_on_shutdown' => true,
		];
		$parsed                         = array_merge( $defaults, $config );
		$parsed[ 'max_jobs_per_run' ]   = max( 1, (int) $parsed[ 'max_jobs_per_run' ] );
		$parsed[ 'max_execution_time' ] = max( 30, (int) $parsed[ 'max_execution_time' ] );
		$parsed[ 'sleep_interval' ]     = max( 1, (int) $parsed[ 'sleep_interval' ] );
		return $parsed;
	}

	private function calculate_success_rate() {
		if ( 0 === $this->stats[ 'jobs_processed' ] ) {
			return 100.0;
		}
		$successful = $this->stats[ 'jobs_processed' ] - $this->stats[ 'jobs_failed' ];
		return ( $successful / $this->stats[ 'jobs_processed' ] ) * 100;
	}

	private function get_memory_limit() {
		$memory_limit = ini_get( 'memory_limit' );
		if ( '-1' === $memory_limit ) {
			return 0;
		}
		$unit  = strtolower( substr( $memory_limit, -1 ) );
		$value = (int) $memory_limit;
		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}
		return $value;
	}

	public static function create_default( Redis_Queue_Manager $queue_manager, Job_Processor $job_processor ) {
		return new self( $queue_manager, $job_processor );
	}

	public function __destruct() {
		if ( $this->config[ 'cleanup_on_shutdown' ] ) {
			function_exists( '\do_action' ) && \do_action( 'redis_queue_demo_worker_shutdown', $this );
		}
	}
}

// Legacy global class alias removed (backward compatibility dropped).
