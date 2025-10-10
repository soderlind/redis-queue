<?php
/**
 * Plugin Name:       Redis Queue Demo
 * Plugin URI:        https://github.com/soderlind/redis-queue-demo
 * Description:       A plugin demonstrating Redis-based job queue management including workers, job types, and a UI.
 * Version:           1.2.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redis-queue-demo
 * Domain Path:       /languages
 * Update URI:        false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REDIS_QUEUE_DEMO_VERSION', '1.2.0' );
define( 'REDIS_QUEUE_DEMO_MIN_PHP', '8.3' );
define( 'REDIS_QUEUE_DEMO_PLUGIN_FILE', __FILE__ );
define( 'REDIS_QUEUE_DEMO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'REDIS_QUEUE_DEMO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REDIS_QUEUE_DEMO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoload if present.
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// Bootstrap namespaced main class.
Soderlind\RedisQueueDemo\Core\Redis_Queue_Demo::get_instance();

function redis_queue_demo() {
	return Soderlind\RedisQueueDemo\Core\Redis_Queue_Demo::get_instance();
}

function redis_queue_enqueue_job( $job_type, $payload = array(), $options = array() ) {
	return redis_queue_demo()->enqueue_job( $job_type, $payload, $options );
}

function redis_queue_process_jobs( $queue = 'default', $max_jobs = null ) {
	$instance = redis_queue_demo();
	if ( ! $instance->get_queue_manager() || ! $instance->get_job_processor() ) {
		return false;
	}
	$worker = new \Soderlind\RedisQueueDemo\Workers\Sync_Worker( $instance->get_queue_manager(), $instance->get_job_processor() );
	return $worker->process_jobs( (array) $queue, $max_jobs );
}
