<?php
/**
 * Jobs Endpoint Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jobs endpoint class.
 * 
 * Handles jobs-related REST API endpoints.
 *
 * @since 1.0.0
 */
class Jobs_Endpoint {

	/**
	 * Queue manager instance.
	 *
	 * @since 1.0.0
	 * @var Redis_Queue_Manager
	 */
	private $queue_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Redis_Queue_Manager $queue_manager Queue manager instance.
	 */
	public function __construct( Redis_Queue_Manager $queue_manager ) {
		$this->queue_manager = $queue_manager;
	}

	/**
	 * Register routes for this endpoint.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// This is handled by the main REST_Controller class
		// This file exists for potential future expansion
	}
}