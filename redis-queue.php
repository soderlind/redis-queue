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
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version and requirements.
define( 'REDIS_QUEUE_VERSION', '2.0.0' );
define( 'REDIS_QUEUE_MIN_PHP', '8.3' );

// Plugin file paths and URLs.
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
 *
 * Migrates plugin options from the old naming convention (redis_queue_demo_*)
 * to the new naming convention (redis_queue_*). This runs once during plugin
 * upgrade from version 1.x to 2.0.0.
 */
function redis_queue_migrate_options_v2() {
	// Check if migration has already been performed.
	if ( get_option( 'redis_queue_migrated_options_v2' ) ) {
		return;
	}

	// Define all option keys that need to be migrated.
	$option_suffixes = [
		'redis_host', 'redis_port', 'redis_password', 'redis_database', 'default_queue', 'max_jobs_per_run',
		'worker_timeout', 'max_retries', 'retry_backoff', 'enable_logging', 'cleanup_completed_jobs', 'cleanup_after_days',
	];

	// Migrate each option from old to new key name.
	foreach ( $option_suffixes as $suffix ) {
		$old_key = 'redis_queue_demo_' . $suffix;
		$new_key = 'redis_queue_' . $suffix;
		$old_val = get_option( $old_key, null );
		
		// Only migrate if old value exists and new value doesn't exist.
		if ( $old_val !== null && get_option( $new_key, null ) === null ) {
			update_option( $new_key, $old_val );
		}
	}

	// Mark migration as complete.
	update_option( 'redis_queue_migrated_options_v2', '1' );
}
add_action( 'plugins_loaded', 'redis_queue_migrate_options_v2', 1 );

/**
 * Bootstrap the main plugin class.
 *
 * Initializes the Redis Queue plugin if all dependencies are loaded.
 * If dependencies are missing (e.g., Composer autoloader not run),
 * displays an admin notice to inform the user.
 */
if ( class_exists( 'Soderlind\RedisQueue\Core\Redis_Queue' ) ) {
	// Initialize the plugin singleton instance.
	Soderlind\RedisQueue\Core\Redis_Queue::get_instance();
} else {
	// Show error notice if dependencies are missing.
	add_action( 'admin_notices', function() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Redis Queue:</strong> ';
			echo esc_html__( 'Plugin dependencies are missing. Please run "composer install" in the plugin directory, or download the plugin from the official release.', 'redis-queue' );
			echo '</p></div>';
		}
	} );
}

/**
 * Get the main plugin instance.
 *
 * Provides global access to the Redis Queue plugin singleton instance.
 * This is the recommended way to access the plugin's public API.
 *
 * @return \Soderlind\RedisQueue\Core\Redis_Queue|null Plugin instance or null if not loaded.
 */
function redis_queue() {
	if ( class_exists( 'Soderlind\RedisQueue\Core\Redis_Queue' ) ) {
		return Soderlind\RedisQueue\Core\Redis_Queue::get_instance();
	}
	return null;
}

/**
 * Enqueue a background job.
 *
 * Convenience function to add a job to the Redis queue. This is a wrapper
 * around the main plugin's enqueue_job method.
 *
 * @param string $job_type The type of job (email, image_processing, api_sync, or custom).
 * @param array  $payload  Job-specific data to be processed.
 * @param array  $options  Optional settings: priority, queue, delay.
 * @return string|false Job ID on success, false on failure.
 */
function redis_queue_enqueue_job( $job_type, $payload = array(), $options = array() ) {
	$instance = redis_queue();
	if ( ! $instance ) {
		return false;
	}
	return $instance->enqueue_job( $job_type, $payload, $options );
}

/**
 * Process queued jobs synchronously.
 *
 * Creates a synchronous worker and processes jobs from the specified queue(s).
 * This can be called from WP-CLI, cron jobs, or custom worker scripts.
 *
 * @param string|array $queue    Queue name(s) to process. Default 'default'.
 * @param int|null     $max_jobs Maximum number of jobs to process. Default from settings.
 * @return array|false Processing results array or false on failure.
 */
function redis_queue_process_jobs( $queue = 'default', $max_jobs = null ) {
	$instance = redis_queue();
	if ( ! $instance || ! $instance->get_queue_manager() || ! $instance->get_job_processor() ) {
		return false;
	}
	
	// Create a synchronous worker and process jobs.
	$worker = new \Soderlind\RedisQueue\Workers\Sync_Worker( $instance->get_queue_manager(), $instance->get_job_processor() );
	return $worker->process_jobs( (array) $queue, $max_jobs );
}
