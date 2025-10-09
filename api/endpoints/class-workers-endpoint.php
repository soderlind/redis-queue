<?php
/**
 * Workers Endpoint Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workers endpoint class.
 * 
 * Handles worker-related REST API endpoints.
 *
 * @since 1.0.0
 */
class Workers_Endpoint {

	/**
	 * Queue manager instance.
	 *
	 * @since 1.0.0
	 * @var Redis_Queue_Manager
	 */
	private $queue_manager;

	/**
	 * Job processor instance.
	 *
	 * @since 1.0.0
	 * @var Job_Processor
	 */
	private $job_processor;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Redis_Queue_Manager $queue_manager Queue manager instance.
	 * @param Job_Processor       $job_processor Job processor instance.
	 */
	public function __construct( Redis_Queue_Manager $queue_manager, Job_Processor $job_processor ) {
		$this->queue_manager = $queue_manager;
		$this->job_processor = $job_processor;
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