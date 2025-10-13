<?php
namespace Soderlind\RedisQueue\Admin;

use Soderlind\RedisQueue\Core\Redis_Queue_Manager;
use Soderlind\RedisQueue\Core\Job_Processor;
use Soderlind\RedisQueue\Workers\Sync_Worker;
use Exception;
use Throwable;

/**
 * Namespaced Admin Interface.
 *
 * This is a faithful port of the legacy global Admin_Interface class with no UI changes.
 * Legacy global alias removed (backward compatibility dropped).
 */
class Admin_Interface {
	private $queue_manager;
	private $job_processor;

	public function __construct( Redis_Queue_Manager $queue_manager, Job_Processor $job_processor ) {
		$this->queue_manager = $queue_manager;
		$this->job_processor = $job_processor;
	}

	public function init() {
		\add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		\add_action( 'wp_ajax_redis_queue_trigger_worker', [ $this, 'ajax_trigger_worker' ] );
		\add_action( 'wp_ajax_redis_queue_get_stats', [ $this, 'ajax_get_stats' ] );
		\add_action( 'wp_ajax_redis_queue_clear_queue', [ $this, 'ajax_clear_queue' ] );
		\add_action( 'wp_ajax_redis_queue_create_test_job', [ $this, 'ajax_create_test_job' ] );
		\add_action( 'wp_ajax_redis_queue_diagnostics', [ $this, 'ajax_diagnostics' ] );
		\add_action( 'wp_ajax_redis_queue_debug_test', [ $this, 'ajax_debug_test' ] );
		\add_action( 'wp_ajax_redis_queue_reset_stuck_jobs', [ $this, 'ajax_reset_stuck_jobs' ] );
		\add_action( 'wp_ajax_redis_queue_purge_jobs', [ $this, 'ajax_purge_jobs' ] );
	}

	public function add_admin_menu() {
		\add_menu_page( __( 'Redis Queue', 'redis-queue' ), __( 'Redis Queue', 'redis-queue' ), 'manage_options', 'redis-queue', [ $this, 'render_dashboard_page' ], 'dashicons-database-view', 30 );
		\add_submenu_page( 'redis-queue', __( 'Dashboard', 'redis-queue' ), __( 'Dashboard', 'redis-queue' ), 'manage_options', 'redis-queue', [ $this, 'render_dashboard_page' ] );
		\add_submenu_page( 'redis-queue', __( 'Jobs', 'redis-queue' ), __( 'Jobs', 'redis-queue' ), 'manage_options', 'redis-queue-jobs', [ $this, 'render_jobs_page' ] );
		\add_submenu_page( 'redis-queue', __( 'Test Jobs', 'redis-queue' ), __( 'Test Jobs', 'redis-queue' ), 'manage_options', 'redis-queue-test', [ $this, 'render_test_page' ] );
		\add_submenu_page( 'redis-queue', __( 'Settings', 'redis-queue' ), __( 'Settings', 'redis-queue' ), 'manage_options', 'redis-queue-settings', [ $this, 'render_settings_page' ] );
	}

	public function enqueue_admin_scripts( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'redis-queue' ) ) {
			return;
		}
		\wp_enqueue_script( 'redis-queue-admin', \plugin_dir_url( __FILE__ ) . '../../assets/admin.js', [ 'jquery' ], REDIS_QUEUE_VERSION, true );
		\wp_localize_script( 'redis-queue-admin', 'redisQueueAdmin', [
			'ajaxUrl'   => \admin_url( 'admin-ajax.php' ),
			'nonce'     => \wp_create_nonce( 'redis_queue_admin' ),
			'restNonce' => \wp_create_nonce( 'wp_rest' ),
			'restUrl'   => \rest_url( 'redis-queue/v1/' ),
			'strings'   => [
				'processing'      => __( 'Processing...', 'redis-queue' ),
				'success'         => __( 'Success!', 'redis-queue' ),
				'error'           => __( 'Error occurred', 'redis-queue' ),
				'confirmClear'    => __( 'Are you sure you want to clear this queue?', 'redis-queue' ),
				'workerTriggered' => __( 'Worker triggered successfully', 'redis-queue' ),
				'queueCleared'    => __( 'Queue cleared successfully', 'redis-queue' ),
			],
		] );
		\wp_enqueue_style( 'redis-queue-admin', \plugin_dir_url( __FILE__ ) . '../../assets/admin.css', [], REDIS_QUEUE_VERSION );
	}

	// The following render methods replicate legacy output exactly.
	public function render_dashboard_page() {
		$stats      = $this->queue_manager->get_queue_stats();
		$flat_stats = $this->flatten_stats( $stats );
		$health     = $this->get_system_health();
		include __DIR__ . '/partials/dashboard-inline.php'; // Provide minimal template or fall back to inline markup if not present.
	}
	public function render_jobs_page() {
		global $wpdb;
		$per_page      = 20;
		$current_page  = isset( $_GET[ 'paged' ] ) ? max( 1, intval( $_GET[ 'paged' ] ) ) : 1;
		$status_filter = isset( $_GET[ 'status' ] ) ? sanitize_text_field( $_GET[ 'status' ] ) : '';
		$table_name    = $wpdb->prefix . 'redis_queue_jobs';
		$offset        = ( $current_page - 1 ) * $per_page;
		$where         = [];
		$args          = [];
		if ( $status_filter ) {
			$where[] = 'status = %s';
			$args[]  = $status_filter;
		}
		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$count_sql    = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
		if ( $args ) {
			$count_sql = $wpdb->prepare( $count_sql, ...$args );
		}
		$total_jobs  = (int) $wpdb->get_var( $count_sql );
		$jobs_sql    = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$args[]      = $per_page;
		$args[]      = $offset;
		$jobs        = $wpdb->get_results( $wpdb->prepare( $jobs_sql, ...$args ), ARRAY_A );
		$total_pages = (int) ceil( $total_jobs / $per_page );
		include __DIR__ . '/partials/jobs-inline.php';
	}
	public function render_test_page() {
		include __DIR__ . '/partials/test-inline.php';
	}
	public function render_settings_page() {
		if ( isset( $_POST[ 'submit' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'redis_queue_settings' ) ) {
			$this->save_settings();
		} elseif ( isset( $_POST[ 'generate_api_token' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'redis_queue_settings' ) ) {
			$_POST[ '__generate_api_token' ] = 1;
			$this->save_settings();
		} elseif ( isset( $_POST[ 'clear_api_token' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'redis_queue_settings' ) ) {
			$_POST[ '__clear_api_token' ] = 1;
			$this->save_settings();
		}
		$options  = get_option( 'redis_queue_settings', [] );
		$defaults = [ 'redis_host' => '127.0.0.1', 'redis_port' => 6379, 'redis_database' => 0, 'redis_password' => '', 'worker_timeout' => 30, 'max_retries' => 3, 'retry_delay' => 60, 'batch_size' => 10, 'api_token' => '', 'api_token_scope' => 'worker', 'rate_limit_per_minute' => 60, 'enable_request_logging' => 0, 'log_rotate_size_kb' => 256, 'log_max_files' => 5 ];
		$options  = wp_parse_args( $options, $defaults );
		include __DIR__ . '/partials/settings-inline.php';
	}

	/* === Settings handling (ported from legacy) === */
	private function save_settings() {
		$existing = get_option( 'redis_queue_settings', [] );
		$settings = [
			'redis_host'             => sanitize_text_field( $_POST[ 'redis_host' ] ?? '' ),
			'redis_port'             => intval( $_POST[ 'redis_port' ] ?? 6379 ),
			'redis_database'         => intval( $_POST[ 'redis_database' ] ?? 0 ),
			'redis_password'         => sanitize_text_field( $_POST[ 'redis_password' ] ?? '' ),
			'worker_timeout'         => intval( $_POST[ 'worker_timeout' ] ?? 30 ),
			'max_retries'            => intval( $_POST[ 'max_retries' ] ?? 3 ),
			'retry_delay'            => intval( $_POST[ 'retry_delay' ] ?? 60 ),
			'batch_size'             => intval( $_POST[ 'batch_size' ] ?? 10 ),
			'api_token'              => $existing[ 'api_token' ] ?? '',
			'api_token_scope'        => ( isset( $_POST[ 'api_token_scope' ] ) && in_array( $_POST[ 'api_token_scope' ], [ 'worker', 'full' ], true ) ) ? $_POST[ 'api_token_scope' ] : ( $existing[ 'api_token_scope' ] ?? 'worker' ),
			'rate_limit_per_minute'  => isset( $_POST[ 'rate_limit_per_minute' ] ) ? max( 1, intval( $_POST[ 'rate_limit_per_minute' ] ) ) : ( $existing[ 'rate_limit_per_minute' ] ?? 60 ),
			'enable_request_logging' => isset( $_POST[ 'enable_request_logging' ] ) ? 1 : 0,
			'log_rotate_size_kb'     => isset( $_POST[ 'log_rotate_size_kb' ] ) ? max( 8, intval( $_POST[ 'log_rotate_size_kb' ] ) ) : ( $existing[ 'log_rotate_size_kb' ] ?? 256 ),
			'log_max_files'          => isset( $_POST[ 'log_max_files' ] ) ? max( 1, intval( $_POST[ 'log_max_files' ] ) ) : ( $existing[ 'log_max_files' ] ?? 5 ),
		];
		if ( isset( $_POST[ '__clear_api_token' ] ) || isset( $_POST[ 'clear_api_token' ] ) ) {
			$settings[ 'api_token' ] = '';
		}
		if ( isset( $_POST[ '__generate_api_token' ] ) || isset( $_POST[ 'generate_api_token' ] ) ) {
			try {
				$settings[ 'api_token' ] = bin2hex( random_bytes( 32 ) );
			} catch (\Exception $e) {
				$settings[ 'api_token' ] = wp_generate_password( 64, false, false );
			}
		}
		update_option( 'redis_queue_settings', $settings );
		add_action( 'admin_notices', [ $this, 'settings_saved_notice' ] );
	}
	public function settings_saved_notice() {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved successfully.', 'redis-queue' ); ?></p>
		</div>
		<?php
	}

	// AJAX handlers (inline versions)
	public function ajax_trigger_worker() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		try {
			if ( ! $this->queue_manager || ! $this->job_processor ) {
				wp_send_json_error( 'Queue system not initialized' );
			}
			if ( ! $this->queue_manager->is_connected() ) {
				wp_send_json_error( 'Redis connection not available' );
			}
			if ( function_exists( 'redis_queue_process_jobs' ) ) {
				$results = redis_queue_process_jobs( [ 'default', 'email', 'media', 'api' ], 10 );
			} else {
				$sync    = new Sync_Worker( $this->queue_manager, $this->job_processor );
				$results = $sync->process_jobs( [ 'default', 'email', 'media', 'api' ], 10 );
			}
			if ( $results === null || ! is_array( $results ) ) {
				wp_send_json_error( 'Worker returned invalid results' );
			}
			wp_send_json_success( $results );
		} catch (\Throwable $e) {
			wp_send_json_error( 'Worker error: ' . ( method_exists( $e, 'getMessage' ) ? $e->getMessage() : 'unknown' ) );
		}
	}
	public function ajax_get_stats() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$stats = $this->queue_manager->get_queue_stats();
		wp_send_json_success( $this->flatten_stats( $stats ) );
	}
	public function ajax_clear_queue() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$queue = sanitize_text_field( $_POST[ 'queue' ] ?? 'default' );
		$ok    = $this->queue_manager->clear_queue( $queue );
		$ok ? wp_send_json_success( [ 'message' => 'Queue cleared successfully' ] ) : wp_send_json_error( 'Failed to clear queue' );
	}
	public function ajax_create_test_job() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$job_type = sanitize_text_field( $_POST[ 'job_type' ] ?? '' );
		$payload  = $_POST[ 'payload' ] ?? [];
		$payload  = is_array( $payload ) ? array_map( 'sanitize_text_field', $payload ) : [];
		try {
			$job_id = redis_queue_demo()->enqueue_job( $job_type, $payload, [ 'priority' => 10 ] );
			if ( $job_id ) {
				wp_send_json_success( [ 'job_id' => $job_id, 'message' => 'Job created and enqueued successfully.' ] );
			} else {
				wp_send_json_error( 'Failed to enqueue job.' );
			}
		} catch (\Exception $e) {
			wp_send_json_error( 'Job creation failed: ' . $e->getMessage() );
		}
	}
	public function ajax_diagnostics() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		try {
			$diagnostics = $this->queue_manager->diagnostic();
			wp_send_json_success( $diagnostics );
		} catch (\Exception $e) {
			wp_send_json_error( 'Diagnostic failed: ' . $e->getMessage() );
		}
	}
	public function ajax_debug_test() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$plugin  = redis_queue_demo();
		$results = [ 'plugin' => [ 'Queue Manager' => $plugin->queue_manager ? 'OK' : 'FAILED', 'Job Processor' => $plugin->job_processor ? 'OK' : 'FAILED' ], 'redis' => [ 'Connected' => $plugin->queue_manager->is_connected() ? 'YES' : 'NO' ] ];
		wp_send_json_success( $results );
	}
	public function ajax_reset_stuck_jobs() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		try {
			$reset = $this->queue_manager->reset_stuck_jobs( 30 );
			wp_send_json_success( [ 'message' => sprintf( 'Reset %d stuck jobs.', $reset ), 'count' => $reset ] );
		} catch (\Exception $e) {
			wp_send_json_error( 'Failed to reset stuck jobs: ' . $e->getMessage() );
		}
	}
	public function ajax_purge_jobs() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		$scope = sanitize_text_field( $_POST[ 'scope' ] ?? '' );
		$days  = max( 1, intval( $_POST[ 'days' ] ?? 7 ) );
		if ( ! in_array( $scope, [ 'completed', 'failed', 'older', 'all' ], true ) ) {
			wp_send_json_error( 'Invalid purge scope.' );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'redis_queue_jobs';
		$where = '';
		$args  = [];
		switch ( $scope ) {
			case 'completed':
				$where = "WHERE status='completed'";
				break;
			case 'failed':
				$where = "WHERE status='failed'";
				break;
			case 'older':
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
				$where = 'WHERE created_at < %s';
				$args[] = $cutoff;
				break;
			case 'all':
			default:
				$where = '';
		}
		$count_sql = "SELECT COUNT(*) FROM $table $where";
		$count     = $args ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : (int) $wpdb->get_var( $count_sql );
		if ( 0 === $count ) {
			wp_send_json_success( [ 'message' => 'No matching jobs to purge.', 'count' => 0 ] );
		}
		$del_sql = "DELETE FROM $table $where";
		if ( $args ) {
			$wpdb->query( $wpdb->prepare( $del_sql, ...$args ) );
		} else {
			$wpdb->query( $del_sql );
		}
		$deleted = $wpdb->rows_affected;
		wp_send_json_success( [ 'message' => ( 'older' === $scope ? sprintf( 'Purged %d jobs older than %d days.', $deleted, $days ) : sprintf( 'Purged %d jobs (scope: %s).', $deleted, $scope ) ), 'count' => $deleted, 'scope' => $scope, 'days' => $days ] );
	}

	// Utility helpers retained (not invoked directly here because legacy handles them, but available for future inline porting)
	private function get_system_health() {
		return [ 'redis' => $this->queue_manager->is_connected(), 'database' => $this->check_database_health(), 'overall' => $this->queue_manager->is_connected() && $this->check_database_health(),];
	}
	private function check_database_health() {
		global $wpdb;
		$table_name   = $wpdb->prefix . 'redis_queue_jobs';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return ! empty( $table_exists );
	}
	private function flatten_stats( $stats ) {
		$flat = [ 'queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0 ];
		if ( isset( $stats[ 'database' ] ) && is_array( $stats[ 'database' ] ) ) {
			$flat[ 'queued' ]     = (int) ( $stats[ 'database' ][ 'queued' ] ?? 0 );
			$flat[ 'processing' ] = (int) ( $stats[ 'database' ][ 'processing' ] ?? 0 );
			$flat[ 'completed' ]  = (int) ( $stats[ 'database' ][ 'completed' ] ?? 0 );
			$flat[ 'failed' ]     = (int) ( $stats[ 'database' ][ 'failed' ] ?? 0 );
			$flat[ 'total' ]      = (int) ( $stats[ 'database' ][ 'total' ] ?? ( $flat[ 'queued' ] + $flat[ 'processing' ] + $flat[ 'completed' ] + $flat[ 'failed' ] ) );
			return $flat;
		}
		foreach ( $stats as $data ) {
			if ( isset( $data[ 'pending' ] ) ) {
				$flat[ 'queued' ] += (int) $data[ 'pending' ];
			}
		}
		$flat[ 'total' ] = $flat[ 'queued' ];
		return $flat;
	}
}

// Legacy global class alias removed (backward compatibility dropped).
