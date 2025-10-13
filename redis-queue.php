<?php
/**
 * Plugin Name:       Redis Queue
 * Plugin URI:        https://github.com/soderlind/redis-queue
 * Description:       Redis-backed prioritized, delayed & retryable background jobs for WordPress (workers, REST API, admin UI).
 * Version:           2.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redis-queue
 * Domain Path:       /languages
 * Update URI:        false
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REDIS_QUEUE_VERSION', '2.0.0' );
define( 'REDIS_QUEUE_MIN_PHP', '8.3' );
define( 'REDIS_QUEUE_PLUGIN_FILE', __FILE__ );
define( 'REDIS_QUEUE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'REDIS_QUEUE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REDIS_QUEUE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoload if present.
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

/**
 * One-time migration of options from old prefix redis_queue_demo_ to redis_queue_.
 */
function redis_queue_migrate_options_v2() {
	if ( get_option( 'redis_queue_migrated_options_v2' ) ) {
		return;
	}
	$option_suffixes = [
		'redis_host', 'redis_port', 'redis_password', 'redis_database', 'default_queue', 'max_jobs_per_run',
		'worker_timeout', 'max_retries', 'retry_backoff', 'enable_logging', 'cleanup_completed_jobs', 'cleanup_after_days',
	];
	foreach ( $option_suffixes as $suffix ) {
		$old_key = 'redis_queue_demo_' . $suffix;
		$new_key = 'redis_queue_' . $suffix;
		$old_val = get_option( $old_key, null );
		if ( $old_val !== null && get_option( $new_key, null ) === null ) {
			update_option( $new_key, $old_val );
		}
	}
	update_option( 'redis_queue_migrated_options_v2', '1' );
}
add_action( 'plugins_loaded', 'redis_queue_migrate_options_v2', 1 );

// Bootstrap namespaced main class (new namespace & class name).
if ( class_exists( 'Soderlind\RedisQueue\Core\Redis_Queue' ) ) {
	Soderlind\RedisQueue\Core\Redis_Queue::get_instance();
} else {
	add_action( 'admin_notices', function() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Redis Queue:</strong> ';
			echo esc_html__( 'Plugin dependencies are missing. Please run "composer install" in the plugin directory, or download the plugin from the official release.', 'redis-queue' );
			echo '</p></div>';
		}
	} );
}

function redis_queue() {
	if ( class_exists( 'Soderlind\RedisQueue\Core\Redis_Queue' ) ) {
		return Soderlind\RedisQueue\Core\Redis_Queue::get_instance();
	}
	return null;
}

function redis_queue_enqueue_job( $job_type, $payload = array(), $options = array() ) {
	$instance = redis_queue();
	if ( ! $instance ) {
		return false;
	}
	return $instance->enqueue_job( $job_type, $payload, $options );
}

function redis_queue_process_jobs( $queue = 'default', $max_jobs = null ) {
	$instance = redis_queue();
	if ( ! $instance || ! $instance->get_queue_manager() || ! $instance->get_job_processor() ) {
		return false;
	}
	$worker = new \Soderlind\RedisQueue\Workers\Sync_Worker( $instance->get_queue_manager(), $instance->get_job_processor() );
	return $worker->process_jobs( (array) $queue, $max_jobs );
}
