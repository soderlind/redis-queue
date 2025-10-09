<?php
/**
 * Debug script for Redis Queue Demo
 * This script tests the core functionality directly
 */

// Bootstrap WordPress
define( 'WP_USE_THEMES', false );
require_once( '../../../wp-load.php' );

// Load the plugin manually if not already loaded
if ( ! class_exists( 'Redis_Queue_Demo' ) ) {
	require_once( __DIR__ . '/redis-queue-demo.php' );
}

// Wait for WordPress to fully load
if ( ! function_exists( 'redis_queue_demo' ) ) {
	die( 'Redis Queue Demo functions not available' );
}

// Get plugin instance
$plugin = redis_queue_demo();

echo "=== Redis Queue Demo Debug ===\n";

// Test 1: Check plugin initialization
echo "1. Plugin initialization:\n";
echo "   - Queue Manager: " . ( is_object( $plugin->queue_manager ) ? 'OK' : 'FAILED' ) . "\n";
echo "   - Job Processor: " . ( is_object( $plugin->job_processor ) ? 'OK' : 'FAILED' ) . "\n";

// Test 2: Check Redis connection
echo "\n2. Redis connection:\n";
if ( $plugin->queue_manager ) {
	echo "   - Connected: " . ( $plugin->queue_manager->is_connected() ? 'YES' : 'NO' ) . "\n";

	// Run diagnostics
	$diagnostics = $plugin->queue_manager->diagnostic();
	echo "   - Test Write: " . ( $diagnostics[ 'test_write' ] ? 'OK' : 'FAILED' ) . "\n";
	echo "   - Test Read: " . ( $diagnostics[ 'test_read' ] ? 'OK' : 'FAILED' ) . "\n";
	echo "   - Queue Prefix: " . $diagnostics[ 'queue_prefix' ] . "\n";
	echo "   - Redis Keys: " . count( $diagnostics[ 'redis_keys' ] ) . "\n";
	if ( ! empty( $diagnostics[ 'redis_keys' ] ) ) {
		echo "   - Keys: " . implode( ', ', $diagnostics[ 'redis_keys' ] ) . "\n";
	}
} else {
	echo "   - Queue manager not available\n";
}

// Test 3: Try creating a simple job
echo "\n3. Job creation test:\n";
try {
	$job_id = $plugin->enqueue_job( 'email', array(
		'to'      => 'test@example.com',
		'subject' => 'Test Email',
		'message' => 'This is a test email',
	) );

	if ( $job_id ) {
		echo "   - Job created: $job_id\n";

		// Check if it's in Redis
		$diagnostics = $plugin->queue_manager->diagnostic();
		echo "   - Redis keys after job creation: " . count( $diagnostics[ 'redis_keys' ] ) . "\n";
		if ( ! empty( $diagnostics[ 'redis_keys' ] ) ) {
			echo "   - Keys: " . implode( ', ', $diagnostics[ 'redis_keys' ] ) . "\n";
		}

		// Try to dequeue it
		$dequeued = $plugin->queue_manager->dequeue( array( 'default' ) );
		if ( $dequeued ) {
			echo "   - Job dequeued successfully: " . $dequeued[ 'job_id' ] . "\n";
			echo "   - Job type: " . $dequeued[ 'job_type' ] . "\n";
			echo "   - Payload keys: " . implode( ', ', array_keys( $dequeued[ 'payload' ] ) ) . "\n";
		} else {
			echo "   - Failed to dequeue job\n";
		}

	} else {
		echo "   - Failed to create job\n";
	}
} catch (Exception $e) {
	echo "   - Exception: " . $e->getMessage() . "\n";
}

echo "\n=== Debug Complete ===\n";