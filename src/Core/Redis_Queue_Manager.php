<?php
namespace Soderlind\RedisQueue\Core;

use Exception;
use Predis\Client as PredisClient;
use Soderlind\RedisQueue\Contracts\Queue_Job;

/**
 * Redis Queue Manager.
 * Handles connecting to Redis, enqueue/dequeue operations, job metadata storage, delayed jobs, and diagnostics.
 */
class Redis_Queue_Manager {
	/** @var \Redis|PredisClient|null */
	private $redis = null;
	private bool $connected = false;
	private string $queue_prefix = 'redis_queue:';

	public function __construct() {
		$this->connect();
		// One-time repair of legacy Redis job entries missing serialized_job.class (carried forward under new option prefix)
		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
			$flag = \get_option( 'redis_queue_repair_v1', '0' );
			if ( '0' === $flag ) {
				$this->repair_redis_jobs();
				\update_option( 'redis_queue_repair_v1', '1' );
			}
			// Migrate legacy redis keys prefix redis_queue_demo: -> redis_queue:
			$migrated_keys = \get_option( 'redis_queue_migrated_redis_keys_v2', '0' );
			if ( '0' === $migrated_keys && $this->connected ) {
				$this->migrate_redis_keys_prefix();
				\update_option( 'redis_queue_migrated_redis_keys_v2', '1' );
			}
		}
	}

	/**
	 * Connect to Redis.
	 * Attempts connection using native Redis extension first, falls back to Predis.
	 * 
	 * @return bool True if connected successfully.
	 */
	private function connect(): bool {
		try {
			// Get Redis connection settings.
			$host     = \redis_queue()->get_option( 'redis_host', '127.0.0.1' );
			$port     = \redis_queue()->get_option( 'redis_port', 6379 );
			$password = \redis_queue()->get_option( 'redis_password', '' );
			$database = \redis_queue()->get_option( 'redis_database', 0 );

			// Try native Redis extension first.
			if ( \extension_loaded( 'redis' ) ) {
				$this->redis = new \Redis();
				$connected   = $this->redis->connect( $host, $port, 2.5 );
				
				if ( $connected ) {
					// Authenticate if password provided.
					if ( ! empty( $password ) ) {
						$this->redis->auth( $password );
					}
					// Select database.
					$this->redis->select( $database );
					$this->connected = true;
				}
			} elseif ( class_exists( PredisClient::class) ) {
				// Fall back to Predis library.
				$config = [
					'scheme'   => 'tcp',
					'host'     => $host,
					'port'     => $port,
					'database' => $database
				];
				
				if ( ! empty( $password ) ) {
					$config[ 'password' ] = $password;
				}
				
				$this->redis = new PredisClient( $config );
				$this->redis->connect();
				$this->connected = true;
			}

			// Fire connection success action.
			if ( $this->connected ) {
				if ( function_exists( 'do_action' ) ) {
					\do_action( 'redis_queue_connected', $this );
				}
			}
			
			return $this->connected;
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: Connection failed - ' . $e->getMessage() );
			$this->connected = false;
			return false;
		}
	}

	/**
	 * Check if Redis connection is active.
	 * Performs a PING command to verify connection.
	 * 
	 * @return bool True if connected and responsive.
	 */
	public function is_connected(): bool {
		if ( ! $this->connected || ! $this->redis ) {
			return false;
		}
		
		try {
			// Ping Redis to verify connection is alive.
			$response = $this->redis->ping();
			return ( $response === true || $response === 'PONG' );
		} catch (Exception $e) {
			$this->connected = false;
			return false;
		}
	}

	/**
	 * Enqueue a job to Redis.
	 * Stores job metadata in database and adds job ID to Redis queue.
	 * 
	 * @param Queue_Job $job   Job instance to enqueue.
	 * @param int|null  $delay Optional delay in seconds before job is available.
	 * @return string|false Job ID on success, false on failure.
	 */
	public function enqueue( Queue_Job $job, $delay = null ) {
		if ( ! $this->is_connected() ) {
			return false;
		}
		
		try {
			// Generate unique job ID.
			$job_id    = $this->generate_job_id();
			$job_data  = $this->prepare_job_data( $job, $job_id );
			$queue_key = $this->get_queue_key( $job->get_queue_name() );

			// Store job metadata in database.
			if ( ! $this->store_job_metadata( $job_id, $job, $job_data ) ) {
				return false;
			}

			if ( $delay && $delay > 0 ) {
				$process_time = time() + $delay;
				$delayed_key  = $this->queue_prefix . 'delayed';
				$this->redis->zadd( $delayed_key, $process_time, json_encode( $job_data ) );
			} else {
				$priority = $job->get_priority();
				$this->redis->zadd( $queue_key, $priority, json_encode( $job_data ) );
			}
			if ( function_exists( 'do_action' ) ) {
				\do_action( 'redis_queue_job_enqueued', $job_id, $job );
			}
			return $job_id;
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: Enqueue failed - ' . $e->getMessage() );
			return false;
		}
	}

	public function dequeue( $queues = [ 'default' ] ) {
		if ( ! $this->is_connected() ) {
			return null;
		}
		if ( is_string( $queues ) ) {
			$queues = [ $queues ];
		}
		try {
			$this->process_delayed_jobs();
			foreach ( $queues as $queue_name ) {
				$queue_key = $this->get_queue_key( $queue_name );
				$jobs      = $this->redis->zrange( $queue_key, 0, 0, [ 'withscores' => true ] );
				if ( ! empty( $jobs ) ) {
					$job_data = array_key_first( $jobs );
					$this->redis->zrem( $queue_key, $job_data );
					$decoded = json_decode( $job_data, true );
					if ( $decoded ) {
						// Skip malformed job entries lacking job_type; they cause downstream empty class warnings.
						if ( empty( $decoded[ 'job_type' ] ) ) {
							\error_log( 'Redis Queue Demo: Skipping dequeued job with empty job_type job_id=' . ( $decoded[ 'job_id' ] ?? 'unknown' ) );
							continue;
						}
						if ( empty( $decoded[ 'serialized_job' ][ 'class' ] ?? '' ) ) {
							$jt       = $decoded[ 'job_type' ] ?? '';
							$map      = [
								'email'            => 'Soderlind\\RedisQueue\\Jobs\\Email_Job',
								'image_processing' => 'Soderlind\\RedisQueue\\Jobs\\Image_Processing_Job',
								'api_sync'         => 'Soderlind\\RedisQueue\\Jobs\\API_Sync_Job',
							];
							$inferred = $map[ strtolower( $jt ) ] ?? null;
							if ( ! $inferred && str_contains( $jt, '\\' ) && class_exists( $jt ) ) {
								$inferred = $jt; // Accept provided FQCN.
							}
							if ( ! isset( $decoded[ 'serialized_job' ] ) || ! is_array( $decoded[ 'serialized_job' ] ) ) {
								$decoded[ 'serialized_job' ] = [];
							}
							if ( $inferred ) {
								$decoded[ 'serialized_job' ][ 'class' ] = $inferred;
								\error_log( 'Redis Queue Demo: Injected missing serialized_job.class during dequeue job_id=' . ( $decoded[ 'job_id' ] ?? 'unknown' ) . ' job_type=' . $jt . ' inferred=' . $inferred );
							} else {
								\error_log( 'Redis Queue Demo: Could not infer class for job_id=' . ( $decoded[ 'job_id' ] ?? 'unknown' ) . ' job_type=' . $jt . ' skipping job to avoid warning (legacy variant no longer supported)' );
								continue; // Skip to avoid empty class warning.
							}
						}
						$this->update_job_status( $decoded[ 'job_id' ], 'processing' );
						if ( function_exists( 'do_action' ) ) {
							\do_action( 'redis_queue_job_dequeued', $decoded );
						}
						return $decoded;
					}
				}
			}
			return null;
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: Dequeue failed - ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Safe class_exists wrapper preventing PHP warning for empty class names.
	 */
	private function safe_class_exists( $class ): bool {
		return is_string( $class ) && $class !== '' && class_exists( $class );
	}

	public function get_queue_stats( $queue_name = null ): array {
		if ( ! $this->is_connected() ) {
			return [];
		}
		try {
			$stats = [];
			if ( $queue_name ) {
				$queue_key            = $this->get_queue_key( $queue_name );
				$stats[ $queue_name ] = [ 'pending' => $this->redis->zcard( $queue_key ), 'size' => $this->redis->zcard( $queue_key ) ];
			} else {
				$pattern = $this->queue_prefix . 'queue:*';
				$keys    = $this->redis->keys( $pattern );
				foreach ( $keys as $key ) {
					$qn           = str_replace( $this->queue_prefix . 'queue:', '', $key );
					$stats[ $qn ] = [ 'pending' => $this->redis->zcard( $key ), 'size' => $this->redis->zcard( $key ) ];
				}
				$delayed_key        = $this->queue_prefix . 'delayed';
				$stats[ 'delayed' ] = [ 'pending' => $this->redis->zcard( $delayed_key ), 'size' => $this->redis->zcard( $delayed_key ) ];
			}
			global $wpdb;
			$table               = $wpdb->prefix . 'redis_queue_jobs';
			$array_a             = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
			$db_stats            = $wpdb->get_row( "SELECT COUNT(*) total, SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) queued, SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) processing, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed FROM {$table}", $array_a );
			$stats[ 'database' ] = $db_stats ?: [];
			return $stats;
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: Get stats failed - ' . $e->getMessage() );
			return [];
		}
	}

	public function clear_queue( $queue_name ) {
		if ( ! $this->is_connected() ) {
			return false;
		}
		try {
			$queue_key = $this->get_queue_key( $queue_name );
			$result    = $this->redis->del( $queue_key );
			if ( function_exists( 'do_action' ) ) {
				\do_action( 'redis_queue_queue_cleared', $queue_name );
			}
			return $result > 0;
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: Clear queue failed - ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Migrate Redis keys from legacy prefix redis_queue_demo: to redis_queue:
	 */
	private function migrate_redis_keys_prefix(): void {
		if ( ! $this->is_connected() ) {
			return;
		}
		try {
			$legacy_prefix = 'redis_queue_demo:';
			$scan          = 0;
			if ( $this->redis instanceof \Redis ) {
				// Use SCAN cursor for native Redis extension.
				do {
					$keys = $this->redis->scan( $scan, $legacy_prefix . '*' );
					if ( $keys ) {
						foreach ( $keys as $legacy_key ) {
							$new_key = 'redis_queue:' . substr( $legacy_key, strlen( $legacy_prefix ) );
							if ( $new_key !== $legacy_key && ! $this->redis->exists( $new_key ) ) {
								$this->redis->rename( $legacy_key, $new_key );
							}
						}
					}
				} while ( $scan > 0 );
			} else {
				// Predis fallback: KEYS (acceptable for small key counts; documented as best-effort).
				$keys = $this->redis->keys( $legacy_prefix . '*' );
				foreach ( $keys as $legacy_key ) {
					$new_key = 'redis_queue:' . substr( $legacy_key, strlen( $legacy_prefix ) );
					if ( $new_key !== $legacy_key && ! $this->redis->exists( $new_key ) ) {
						$this->redis->rename( $legacy_key, $new_key );
					}
				}
			}
		} catch (Exception $e) {
			\error_log( 'Redis Queue: Key prefix migration failed - ' . $e->getMessage() );
		}
	}

	private function process_delayed_jobs(): int {
		if ( ! $this->is_connected() ) {
			return 0;
		}
		try {
			$delayed_key = $this->queue_prefix . 'delayed';
			$now         = time();
			$moved       = 0;
			$ready       = $this->redis->zrangebyscore( $delayed_key, 0, $now );
			foreach ( $ready as $job_data ) {
				$decoded = json_decode( $job_data, true );
				if ( $decoded && isset( $decoded[ 'queue_name' ] ) ) {
					$queue_key = $this->get_queue_key( $decoded[ 'queue_name' ] );
					$priority  = $decoded[ 'priority' ] ?? 50;
					$this->redis->zadd( $queue_key, $priority, $job_data );
					$this->redis->zrem( $delayed_key, $job_data );
					$moved++;
				}
			}
			return $moved;
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: Process delayed jobs failed - ' . $e->getMessage() );
			return 0;
		}
	}

	private function generate_job_id(): string {
		return 'job_' . uniqid() . '_' . rand( 1000, 9999 );
	}

	/**
	 * Attempt to repair existing Redis queue entries created before namespaced serialization added the class field.
	 * Adds serialized_job.class when it can be inferred; removes irreparable entries to avoid repeated warnings.
	 */
	private function repair_redis_jobs(): void {
		if ( ! $this->is_connected() ) {
			return;
		}
		try {
			$pattern = $this->queue_prefix . 'queue:*';
			$keys    = $this->redis->keys( $pattern );
			$map     = [
				'email'            => 'Soderlind\\RedisQueue\\Jobs\\Email_Job',
				'image_processing' => 'Soderlind\\RedisQueue\\Jobs\\Image_Processing_Job',
				'api_sync'         => 'Soderlind\\RedisQueue\\Jobs\\API_Sync_Job',
			];
			$fixed   = 0;
			$removed = 0;
			foreach ( $keys as $key ) {
				$entries = $this->redis->zrange( $key, 0, -1 );
				foreach ( $entries as $raw ) {
					$decoded = json_decode( $raw, true );
					if ( ! is_array( $decoded ) ) {
						continue;
					}
					$jt_raw = $decoded[ 'job_type' ] ?? '';
					$jt     = is_string( $jt_raw ) ? trim( $jt_raw ) : '';
					if ( $jt === '' ) {
						// Irreparable: no job_type to infer from.
						$this->redis->zrem( $key, $raw );
						$removed++;
						continue;
					}
					if ( empty( $decoded[ 'serialized_job' ][ 'class' ] ?? '' ) ) {
						$jt_norm  = strtolower( $jt );
						$inferred = $map[ $jt_norm ] ?? null;
						if ( ! $inferred && str_contains( $jt, '\\' ) && class_exists( $jt ) ) {
							$inferred = $jt;
						}
						if ( $inferred ) {
							if ( ! isset( $decoded[ 'serialized_job' ] ) || ! is_array( $decoded[ 'serialized_job' ] ) ) {
								$decoded[ 'serialized_job' ] = [];
							}
							$decoded[ 'serialized_job' ][ 'class' ] = $inferred;
							$this->redis->zrem( $key, $raw );
							$this->redis->zadd( $key, $decoded[ 'priority' ] ?? 50, json_encode( $decoded ) );
							$fixed++;
						} else {
							$this->redis->zrem( $key, $raw );
							$removed++;
						}
					}
				}
			}
			// Log summary only once after processing all keys.
			if ( $fixed || $removed ) {
				\error_log( 'Redis Queue Demo: repair_redis_jobs fixed=' . $fixed . ' removed=' . $removed );
			}
		} catch (Exception $e) {
			\error_log( 'Redis Queue Demo: repair_redis_jobs failed - ' . $e->getMessage() );
		}
	}
	private function prepare_job_data( Queue_Job $job, $job_id ): array {
		$serialized = $job->serialize();
		if ( empty( $serialized[ 'class' ] ) ) {
			$serialized[ 'class' ] = get_class( $job );
			\error_log( 'Redis Queue Demo: Added missing class to serialized_job in prepare_job_data for job_id ' . $job_id . ' (' . $serialized[ 'class' ] . ')' );
		}
		return [ 'job_id' => $job_id, 'job_type' => $job->get_job_type(), 'queue_name' => $job->get_queue_name(), 'priority' => $job->get_priority(), 'payload' => $job->get_payload(), 'timeout' => $job->get_timeout(), 'max_attempts' => $job->get_retry_attempts(), 'created_at' => date( 'Y-m-d H:i:s' ), 'serialized_job' => $serialized,];
	}

	private function store_job_metadata( $job_id, Queue_Job $job, $job_data ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'redis_queue_jobs';
		$result = $wpdb->insert( $table, [ 'job_id' => $job_id, 'job_type' => $job->get_job_type(), 'queue_name' => $job->get_queue_name(), 'priority' => $job->get_priority(), 'status' => 'queued', 'payload' => json_encode( $job->get_payload() ), 'attempts' => 0, 'max_attempts' => $job->get_retry_attempts(), 'timeout' => $job->get_timeout(), 'created_at' => date( 'Y-m-d H:i:s' ), 'updated_at' => date( 'Y-m-d H:i:s' ),], [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ] );
		return $result !== false;
	}

	public function update_job_status( $job_id, $status ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'redis_queue_jobs';
		$update = [ 'status' => $status, 'updated_at' => date( 'Y-m-d H:i:s' ) ];
		if ( 'processing' === $status ) {
			$update[ 'processed_at' ] = date( 'Y-m-d H:i:s' );
		} elseif ( 'failed' === $status ) {
			$update[ 'failed_at' ] = date( 'Y-m-d H:i:s' );
		}
		$result = $wpdb->update( $table, $update, [ 'job_id' => $job_id ], [ '%s', '%s' ], [ '%s' ] );
		return $result !== false;
	}
	private function get_queue_key( $queue_name ): string {
		return $this->queue_prefix . 'queue:' . $queue_name;
	}
	public function get_redis_connection() {
		return $this->redis;
	}
	public function reset_stuck_jobs( $timeout_minutes = 30 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'redis_queue_jobs';
		$result = $wpdb->update( $table, [ 'status' => 'queued', 'updated_at' => date( 'Y-m-d H:i:s' ), 'processed_at' => null ], [ 'status' => 'processing' ], [ '%s', '%s', '%s' ], [ '%s' ] );
		return $result !== false ? $result : 0;
	}
	public function diagnostic(): array {
		$results = [ 'connected' => $this->is_connected(), 'redis_keys' => [], 'test_write' => false, 'test_read' => false, 'queue_prefix' => $this->queue_prefix ];
		if ( $this->is_connected() ) {
			try {
				$test_key = $this->queue_prefix . 'test';
				$this->redis->set( $test_key, 'test_value', 10 );
				$results[ 'test_write' ] = true;
				$read                    = $this->redis->get( $test_key );
				$results[ 'test_read' ]  = ( $read === 'test_value' );
				$this->redis->del( $test_key );
				$results[ 'redis_keys' ] = $this->redis->keys( $this->queue_prefix . '*' );
			} catch (Exception $e) {
				$results[ 'error' ] = $e->getMessage();
			}
		}
		return $results;
	}
	public function __destruct() {
		if ( $this->redis && $this->connected ) {
			try {
				if ( \extension_loaded( 'redis' ) && $this->redis instanceof \Redis ) {
					$this->redis->close();
				} elseif ( $this->redis instanceof PredisClient ) {
					$this->redis->disconnect();
				}
			} catch (Exception $e) {
			}
		}
	}
}

// Legacy global class alias removed (backward compatibility dropped).
