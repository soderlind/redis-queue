<?php
namespace Soderlind\RedisQueueDemo\API;

use Soderlind\RedisQueueDemo\Core\Redis_Queue_Manager;
use Soderlind\RedisQueueDemo\Core\Job_Processor;
use Soderlind\RedisQueueDemo\Workers\Sync_Worker;
use Soderlind\RedisQueueDemo\Jobs\Email_Job;
use Soderlind\RedisQueueDemo\Jobs\Image_Processing_Job;
use Soderlind\RedisQueueDemo\Jobs\API_Sync_Job;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

/**
 * Full namespaced REST controller migrated from legacy global REST_Controller.
 * Behaviour preserved; only namespace changes + internal job class references updated.
 */
class REST_Controller {
	public const NAMESPACE = 'redis-queue/v1';

	private $queue_manager;
	private $job_processor;
	private $sync_worker;

	private $last_auth_method = 'none';
	private $last_scope_allowed = false;
	private $last_rate_limited = false;
	private $last_token_used = '';

	public function __construct( Redis_Queue_Manager $queue_manager, Job_Processor $job_processor ) {
		$this->queue_manager = $queue_manager;
		$this->job_processor = $job_processor;
		$this->sync_worker   = new Sync_Worker( $queue_manager, $job_processor );
		\add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
			return $this->maybe_log_request( $response, $request );
		}, 10, 3 );
	}

	public function register_routes() {
		\register_rest_route( self::NAMESPACE , '/jobs', [
			[ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'get_jobs' ], 'permission_callback' => [ $this, 'check_permissions' ], 'args' => $this->get_collection_params() ],
			[ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'create_job' ], 'permission_callback' => [ $this, 'check_permissions' ], 'args' => $this->get_create_job_params() ],
		] );
		\register_rest_route( self::NAMESPACE , '/jobs/(?P<id>[a-zA-Z0-9_-]+)', [
			[ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'get_job' ], 'permission_callback' => [ $this, 'check_permissions' ] ],
			[ 'methods' => WP_REST_Server::DELETABLE, 'callback' => [ $this, 'delete_job' ], 'permission_callback' => [ $this, 'check_permissions' ] ],
		] );
		\register_rest_route( self::NAMESPACE , '/workers/trigger', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'trigger_worker' ], 'permission_callback' => [ $this, 'check_permissions' ], 'args' => $this->get_trigger_worker_params() ] );
		\register_rest_route( self::NAMESPACE , '/workers/status', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'get_worker_status' ], 'permission_callback' => [ $this, 'check_permissions' ] ] );
		\register_rest_route( self::NAMESPACE , '/stats', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'get_stats' ], 'permission_callback' => [ $this, 'check_permissions' ] ] );
		\register_rest_route( self::NAMESPACE , '/health', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'get_health' ], 'permission_callback' => [ $this, 'check_permissions' ] ] );
		\register_rest_route( self::NAMESPACE , '/queues/(?P<name>[a-zA-Z0-9_-]+)/clear', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'clear_queue' ], 'permission_callback' => [ $this, 'check_admin_permissions' ] ] );
	}

	public function get_jobs( $request ) {
		global $wpdb;
		$per_page         = $request->get_param( 'per_page' ) ?: 10;
		$page             = $request->get_param( 'page' ) ?: 1;
		$status           = $request->get_param( 'status' );
		$queue            = $request->get_param( 'queue' );
		$offset           = ( $page - 1 ) * $per_page;
		$table_name       = $wpdb->prefix . 'redis_queue_jobs';
		$where_conditions = [];
		$prepare_values   = [];
		if ( $status ) {
			$where_conditions[] = 'status = %s';
			$prepare_values[]   = $status;
		}
		if ( $queue ) {
			$where_conditions[] = 'queue_name = %s';
			$prepare_values[]   = $queue;
		}
		$where_clause = empty( $where_conditions ) ? '' : 'WHERE ' . implode( ' AND ', $where_conditions );
		$count_query  = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
		if ( ! empty( $prepare_values ) ) {
			$count_query = $wpdb->prepare( $count_query, ...$prepare_values );
		}
		$total            = (int) $wpdb->get_var( $count_query );
		$jobs_query       = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$prepare_values[] = $per_page;
		$prepare_values[] = $offset;
		$jobs             = $wpdb->get_results( $wpdb->prepare( $jobs_query, ...$prepare_values ), ARRAY_A );
		$formatted_jobs   = [];
		foreach ( $jobs as $job ) {
			$formatted_jobs[] = $this->format_job_response( $job );
		}
		$response = \rest_ensure_response( $formatted_jobs );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );
		return $response;
	}

	public function get_job( $request ) {
		global $wpdb;
		$job_id     = $request->get_param( 'id' );
		$table_name = $wpdb->prefix . 'redis_queue_jobs';
		$job        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE job_id = %s", $job_id ), ARRAY_A );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found', __( 'Job not found.', 'redis-queue-demo' ), [ 'status' => 404 ] );
		}
		return \rest_ensure_response( $this->format_job_response( $job ) );
	}

	public function create_job( $request ) {
		$job_type = $request->get_param( 'type' );
		$payload  = $request->get_param( 'payload' ) ?: [];
		$priority = $request->get_param( 'priority' ) ?: 50;
		$queue    = $request->get_param( 'queue' ) ?: 'default';
		try {
			$job = $this->create_job_instance( $job_type, $payload );
			if ( ! $job ) {
				return new WP_Error( 'invalid_job_type', __( 'Invalid job type specified.', 'redis-queue-demo' ), [ 'status' => 400 ] );
			}
			$job->set_priority( $priority );
			$job->set_queue_name( $queue );
			$job_id = $this->queue_manager->enqueue( $job );
			if ( ! $job_id ) {
				return new WP_Error( 'enqueue_failed', __( 'Failed to enqueue job.', 'redis-queue-demo' ), [ 'status' => 500 ] );
			}
			return \rest_ensure_response( [ 'success' => true, 'job_id' => $job_id, 'message' => __( 'Job created and enqueued successfully.', 'redis-queue-demo' ) ] );
		} catch (Exception $e) {
			return new WP_Error( 'job_creation_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function delete_job( $request ) {
		global $wpdb;
		$job_id     = $request->get_param( 'id' );
		$table_name = $wpdb->prefix . 'redis_queue_jobs';
		$job        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE job_id = %s AND status IN ('queued','failed')", $job_id ) );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found_or_not_cancellable', __( 'Job not found or cannot be cancelled.', 'redis-queue-demo' ), [ 'status' => 404 ] );
		}
		$updated = $wpdb->update( $table_name, [ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ], [ 'job_id' => $job_id ], [ '%s', '%s' ], [ '%s' ] );
		if ( false === $updated ) {
			return new WP_Error( 'job_cancellation_failed', __( 'Failed to cancel job.', 'redis-queue-demo' ), [ 'status' => 500 ] );
		}
		return \rest_ensure_response( [ 'success' => true, 'job_id' => $job_id, 'message' => __( 'Job cancelled successfully.', 'redis-queue-demo' ) ] );
	}

	public function trigger_worker( $request ) {
		$queues   = $request->get_param( 'queues' ) ?: [ 'default' ];
		$max_jobs = $request->get_param( 'max_jobs' ) ?: 10;
		if ( ! is_array( $queues ) ) {
			$queues = [ $queues ];
		}
		try {
			$results = $this->sync_worker->process_jobs( $queues, $max_jobs );
			return \rest_ensure_response( [ 'success' => $results[ 'success' ], 'data' => $results, 'message' => sprintf( __( 'Worker processed %d jobs.', 'redis-queue-demo' ), $results[ 'processed' ] ?? 0 ) ] );
		} catch (Exception $e) {
			return new WP_Error( 'worker_execution_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	public function get_worker_status( $request ) {
		$status = $this->sync_worker->get_status();
		return \rest_ensure_response( [ 'success' => true, 'data' => $status ] );
	}
	public function get_stats( $request ) {
		$queue_name = $request->get_param( 'queue' );
		$stats      = $this->queue_manager->get_queue_stats( $queue_name );
		return \rest_ensure_response( [ 'success' => true, 'data' => $stats ] );
	}

	public function get_health( $request ) {
		$health = [
			'redis_connected'   => $this->queue_manager->is_connected(),
			'redis_info'        => [],
			'database_status'   => $this->check_database_health(),
			'memory_usage'      => [ 'current' => memory_get_usage( true ), 'peak' => memory_get_peak_usage( true ), 'limit' => ini_get( 'memory_limit' ) ],
			'php_version'       => PHP_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'plugin_version'    => \defined( 'REDIS_QUEUE_DEMO_VERSION' ) ? REDIS_QUEUE_DEMO_VERSION : 'unknown',
		];
		if ( $health[ 'redis_connected' ] ) {
			try {
				$redis = $this->queue_manager->get_redis_connection();
				if ( $redis && method_exists( $redis, 'info' ) ) {
					$info                   = $redis->info();
					$health[ 'redis_info' ] = [ 'redis_version' => $info[ 'redis_version' ] ?? 'unknown', 'used_memory' => $info[ 'used_memory_human' ] ?? 'unknown', 'connected_clients' => $info[ 'connected_clients' ] ?? 'unknown' ];
				}
			} catch (Exception $e) {
				$health[ 'redis_info' ][ 'error' ] = $e->getMessage();
			}
		}
		$overall = $health[ 'redis_connected' ] && $health[ 'database_status' ];
		return \rest_ensure_response( [ 'success' => $overall, 'status' => $overall ? 'healthy' : 'unhealthy', 'data' => $health ] );
	}

	public function clear_queue( $request ) {
		$queue_name = $request->get_param( 'name' );
		if ( empty( $queue_name ) ) {
			return new WP_Error( 'missing_queue_name', __( 'Queue name is required.', 'redis-queue-demo' ), [ 'status' => 400 ] );
		}
		$result = $this->queue_manager->clear_queue( $queue_name );
		if ( $result ) {
			return \rest_ensure_response( [ 'success' => true, 'message' => sprintf( __( 'Queue "%s" cleared successfully.', 'redis-queue-demo' ), $queue_name ) ] );
		}
		return new WP_Error( 'queue_clear_failed', __( 'Failed to clear queue.', 'redis-queue-demo' ), [ 'status' => 500 ] );
	}

	public function check_permissions( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			$this->last_auth_method = 'cap';
			return true;
		}
		$settings     = get_option( 'redis_queue_settings', [] );
		$api_token    = $settings[ 'api_token' ] ?? '';
		$scope        = $settings[ 'api_token_scope' ] ?? 'worker';
		$rate_per_min = isset( $settings[ 'rate_limit_per_minute' ] ) ? (int) $settings[ 'rate_limit_per_minute' ] : 60;
		$rate_per_min = $rate_per_min > 0 ? $rate_per_min : 60;
		if ( ! empty( $api_token ) ) {
			$provided    = '';
			$auth_header = $request->get_header( 'authorization' );
			if ( $auth_header && stripos( $auth_header, 'bearer ' ) === 0 ) {
				$provided = trim( substr( $auth_header, 7 ) );
			}
			if ( empty( $provided ) ) {
				$provided = $request->get_header( 'x-redis-queue-token' );
			}
			if ( ! empty( $provided ) && hash_equals( $api_token, $provided ) ) {
				$this->last_auth_method = 'token';
				$this->last_token_used  = $provided;
				$route                  = $request->get_route();
				$allowed                = true;
				if ( 'full' !== $scope ) {
					$allowed_routes = apply_filters( 'redis_queue_demo_token_allowed_routes', [ '/redis-queue/v1/workers/trigger' ], $scope );
					$allowed        = in_array( $route, $allowed_routes, true );
				}
				$allowed                  = apply_filters( 'redis_queue_demo_token_scope_allow', $allowed, $scope, $request );
				$this->last_scope_allowed = $allowed;
				if ( ! $allowed ) {
					return new WP_Error( 'rest_forbidden_scope', __( 'Token scope does not permit this endpoint.', 'redis-queue-demo' ), [ 'status' => 403 ] );
				}
				if ( $rate_per_min > 0 ) {
					if ( ! $this->enforce_rate_limit( $provided, $rate_per_min ) ) {
						$this->last_rate_limited = true;
						return new WP_Error( 'rate_limited', __( 'Rate limit exceeded. Try again later.', 'redis-queue-demo' ), [ 'status' => 429 ] );
					}
				}
				return true;
			}
		}
		$this->last_auth_method = 'none';
		return new WP_Error( 'rest_forbidden', __( 'You do not have permission to access this endpoint.', 'redis-queue-demo' ), [ 'status' => 403 ] );
	}

	public function check_admin_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to perform this action.', 'redis-queue-demo' ), [ 'status' => 403 ] );
		}
		return true;
	}

	private function enforce_rate_limit( $token, $per_minute ) {
		$key_root = 'redis_queue_demo_rate_' . substr( hash( 'sha256', $token ), 0, 24 );
		$minute   = gmdate( 'YmdHi' );
		$key      = $key_root . '_' . $minute;
		$count    = (int) get_transient( $key );
		$count++;
		if ( 1 === $count ) {
			$ttl = 60 - (int) gmdate( 's' );
			set_transient( $key, 1, $ttl );
			return true;
		}
		if ( $count > $per_minute ) {
			return false;
		}
		$ttl = 60 - (int) gmdate( 's' );
		set_transient( $key, $count, $ttl );
		return true;
	}

	private function maybe_log_request( $response, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::NAMESPACE) ) {
			return $response;
		}
		$settings = get_option( 'redis_queue_settings', [] );
		if ( empty( $settings[ 'enable_request_logging' ] ) ) {
			return $response;
		}
		$rotate_kb   = isset( $settings[ 'log_rotate_size_kb' ] ) ? (int) $settings[ 'log_rotate_size_kb' ] : 256;
		$max_files   = isset( $settings[ 'log_max_files' ] ) ? (int) $settings[ 'log_max_files' ] : 5;
		$rotate_kb   = $rotate_kb > 8 ? $rotate_kb : 256;
		$max_files   = $max_files > 0 ? $max_files : 5;
		$status_code = ( $response instanceof WP_REST_Response ) ? $response->get_status() : 0;
		$line        = wp_json_encode( [ 'ts' => gmdate( 'c' ), 'method' => $request->get_method(), 'route' => $route, 'status' => $status_code, 'auth' => $this->last_auth_method, 'scope_ok' => $this->last_scope_allowed, 'rate_limited' => $this->last_rate_limited, 'user_id' => get_current_user_id(), 'ip' => $_SERVER[ 'REMOTE_ADDR' ] ?? '' ] );
		$this->append_log_line( $line, $rotate_kb, $max_files );
		return $response;
	}

	private function append_log_line( $line, $rotate_kb, $max_files ) {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir[ 'basedir' ] ) . 'redis-queue-demo-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$log_file     = trailingslashit( $dir ) . 'requests.log';
		$rotate_bytes = $rotate_kb * 1024;
		if ( file_exists( $log_file ) && filesize( $log_file ) > $rotate_bytes ) {
			$rotated = trailingslashit( $dir ) . 'requests-' . gmdate( 'Ymd-His' ) . '.log';
			@rename( $log_file, $rotated );
			$files = glob( trailingslashit( $dir ) . 'requests-*.log' );
			if ( is_array( $files ) && count( $files ) > $max_files ) {
				sort( $files );
				$excess = array_slice( $files, 0, count( $files ) - $max_files );
				foreach ( $excess as $old ) {
					@unlink( $old );
				}
			}
		}
		$fh = @fopen( $log_file, 'ab' );
		if ( $fh ) {
			fwrite( $fh, $line . PHP_EOL );
			fclose( $fh );
		}
	}

	private function create_job_instance( $job_type, $payload ) {
		return match ( $job_type ) {
			'email'            => new Email_Job( $payload ),
			'image_processing' => new Image_Processing_Job( $payload ),
			'api_sync'         => new API_Sync_Job( $payload ),
			default            => null,
		};
	}

	private function format_job_response( $job ) {
		$payload = json_decode( $job[ 'payload' ], true );
		$result  = $job[ 'result' ] ? json_decode( $job[ 'result' ], true ) : null;
		return [
			'id'            => $job[ 'job_id' ],
			'type'          => $job[ 'job_type' ],
			'queue'         => $job[ 'queue_name' ],
			'status'        => $job[ 'status' ],
			'priority'      => (int) $job[ 'priority' ],
			'payload'       => $payload,
			'result'        => $result,
			'attempts'      => (int) $job[ 'attempts' ],
			'max_attempts'  => (int) $job[ 'max_attempts' ],
			'timeout'       => (int) $job[ 'timeout' ],
			'error_message' => $job[ 'error_message' ],
			'created_at'    => $job[ 'created_at' ],
			'updated_at'    => $job[ 'updated_at' ],
			'processed_at'  => $job[ 'processed_at' ],
			'failed_at'     => $job[ 'failed_at' ],
		];
	}

	private function check_database_health() {
		global $wpdb;
		$table = $wpdb->prefix . 'redis_queue_jobs';
		return ! empty( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) );
	}
	private function get_collection_params() {
		return [];
	}
	private function get_create_job_params() {
		return [];
	}
	private function get_trigger_worker_params() {
		return [];
	}
}

// Legacy global class alias removed (backward compatibility dropped).
