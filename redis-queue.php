<?php

/**
 * Plugin Name:       Redis Queue
 * Plugin URI:        https://github.com/soderlind/redis-queue
 * Description:       Redis-based job queue management for WordPress including workers, job types, and a UI.
 * Version:           1.3.0
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

define( 'REDIS_QUEUE_VERSION', '1.3.0' );
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

// Bootstrap namespaced main class.
Soderlind\RedisQueue\Core\Redis_Queue::get_instance();

function redis_queue() {
	return Soderlind\RedisQueue\Core\Redis_Queue::get_instance();
}

function redis_queue_enqueue_job( $job_type, $payload = array(), $options = array() ) {
	return redis_queue()->enqueue_job( $job_type, $payload, $options );
}

function redis_queue_process_jobs( $queue = 'default', $max_jobs = null ) {
	$instance = redis_queue();
	if ( ! $instance->get_queue_manager() || ! $instance->get_job_processor() ) {
		return false;
	}
	$worker = new \Soderlind\RedisQueue\Workers\Sync_Worker( $instance->get_queue_manager(), $instance->get_job_processor() );
	return $worker->process_jobs( (array) $queue, $max_jobs );
}
