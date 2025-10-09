<?php
/**
 * REST API Controller Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller class.
 * 
 * Handles registration and routing of REST API endpoints.
 *
 * @since 1.0.0
 */
class REST_Controller {

	/**
	 * API namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NAMESPACE = 'redis-queue/v1';

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
	 * Sync worker instance.
	 *
	 * @since 1.0.0
	 * @var Sync_Worker
	 */
	private $sync_worker;

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
		$this->sync_worker   = new Sync_Worker( $queue_manager, $job_processor );
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Jobs endpoints.
		register_rest_route(
			self::NAMESPACE ,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_jobs' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => $this->get_create_job_params(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE ,
			'/jobs/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_job' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// Workers endpoints.
		register_rest_route(
			self::NAMESPACE ,
			'/workers/trigger',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'trigger_worker' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => $this->get_trigger_worker_params(),
			)
		);

		register_rest_route(
			self::NAMESPACE ,
			'/workers/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_worker_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Queue statistics endpoints.
		register_rest_route(
			self::NAMESPACE ,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE ,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Queue management endpoints.
		register_rest_route(
			self::NAMESPACE ,
			'/queues/(?P<name>[a-zA-Z0-9_-]+)/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_queue' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * Get jobs from the queue.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_jobs( $request ) {
		global $wpdb;

		$per_page = $request->get_param( 'per_page' ) ?: 10;
		$page     = $request->get_param( 'page' ) ?: 1;
		$status   = $request->get_param( 'status' );
		$queue    = $request->get_param( 'queue' );

		$offset     = ( $page - 1 ) * $per_page;
		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		// Build query.
		$where_conditions = array();
		$prepare_values   = array();

		if ( $status ) {
			$where_conditions[] = 'status = %s';
			$prepare_values[]   = $status;
		}

		if ( $queue ) {
			$where_conditions[] = 'queue_name = %s';
			$prepare_values[]   = $queue;
		}

		$where_clause = '';
		if ( ! empty( $where_conditions ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where_conditions );
		}

		// Get total count.
		$count_query = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
		if ( ! empty( $prepare_values ) ) {
			$count_query = $wpdb->prepare( $count_query, ...$prepare_values );
		}
		$total = (int) $wpdb->get_var( $count_query );

		// Get jobs.
		$jobs_query       = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$prepare_values[] = $per_page;
		$prepare_values[] = $offset;

		$jobs = $wpdb->get_results(
			$wpdb->prepare( $jobs_query, ...$prepare_values ),
			ARRAY_A
		);

		// Format jobs for response.
		$formatted_jobs = array();
		foreach ( $jobs as $job ) {
			$formatted_jobs[] = $this->format_job_response( $job );
		}

		$response = rest_ensure_response( $formatted_jobs );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Get a single job by ID.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_job( $request ) {
		global $wpdb;

		$job_id     = $request->get_param( 'id' );
		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE job_id = %s", $job_id ),
			ARRAY_A
		);

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found',
				__( 'Job not found.', 'redis-queue-demo' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->format_job_response( $job ) );
	}

	/**
	 * Create a new job.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function create_job( $request ) {
		$job_type = $request->get_param( 'type' );
		$payload  = $request->get_param( 'payload' ) ?: array();
		$priority = $request->get_param( 'priority' ) ?: 50;
		$queue    = $request->get_param( 'queue' ) ?: 'default';

		try {
			// Create job instance based on type.
			$job = $this->create_job_instance( $job_type, $payload );
			if ( ! $job ) {
				return new WP_Error(
					'invalid_job_type',
					__( 'Invalid job type specified.', 'redis-queue-demo' ),
					array( 'status' => 400 )
				);
			}

			// Set job properties.
			$job->set_priority( $priority );
			$job->set_queue_name( $queue );

			// Enqueue the job.
			$job_id = $this->queue_manager->enqueue( $job );

			if ( ! $job_id ) {
				return new WP_Error(
					'enqueue_failed',
					__( 'Failed to enqueue job.', 'redis-queue-demo' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'job_id'  => $job_id,
					'message' => __( 'Job created and enqueued successfully.', 'redis-queue-demo' ),
				)
			);

		} catch (Exception $e) {
			return new WP_Error(
				'job_creation_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Delete (cancel) a job.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function delete_job( $request ) {
		global $wpdb;

		$job_id     = $request->get_param( 'id' );
		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		// Check if job exists and is cancellable.
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE job_id = %s AND status IN ('queued', 'failed')",
				$job_id
			)
		);

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found_or_not_cancellable',
				__( 'Job not found or cannot be cancelled.', 'redis-queue-demo' ),
				array( 'status' => 404 )
			);
		}

		// Update job status to cancelled.
		$updated = $wpdb->update(
			$table_name,
			array(
				'status'     => 'cancelled',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'job_cancellation_failed',
				__( 'Failed to cancel job.', 'redis-queue-demo' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'job_id'  => $job_id,
				'message' => __( 'Job cancelled successfully.', 'redis-queue-demo' ),
			)
		);
	}

	/**
	 * Trigger worker to process jobs.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function trigger_worker( $request ) {
		$queues   = $request->get_param( 'queues' ) ?: array( 'default' );
		$max_jobs = $request->get_param( 'max_jobs' ) ?: 10;

		if ( ! is_array( $queues ) ) {
			$queues = array( $queues );
		}

		try {
			$results = $this->sync_worker->process_jobs( $queues, $max_jobs );

			return rest_ensure_response(
				array(
					'success' => $results[ 'success' ],
					'data'    => $results,
					'message' => sprintf(
						/* translators: %d: number of jobs processed */
						__( 'Worker processed %d jobs.', 'redis-queue-demo' ),
						$results[ 'processed' ] ?? 0
					),
				)
			);

		} catch (Exception $e) {
			return new WP_Error(
				'worker_execution_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get worker status.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_worker_status( $request ) {
		$status = $this->sync_worker->get_status();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $status,
			)
		);
	}

	/**
	 * Get queue statistics.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_stats( $request ) {
		$queue_name = $request->get_param( 'queue' );
		$stats      = $this->queue_manager->get_queue_stats( $queue_name );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $stats,
			)
		);
	}

	/**
	 * Get system health check.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_health( $request ) {
		$health = array(
			'redis_connected'   => $this->queue_manager->is_connected(),
			'redis_info'        => array(),
			'database_status'   => $this->check_database_health(),
			'memory_usage'      => array(
				'current' => memory_get_usage( true ),
				'peak'    => memory_get_peak_usage( true ),
				'limit'   => ini_get( 'memory_limit' ),
			),
			'php_version'       => PHP_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'plugin_version'    => REDIS_QUEUE_DEMO_VERSION,
		);

		// Get Redis info if connected.
		if ( $health[ 'redis_connected' ] ) {
			try {
				$redis = $this->queue_manager->get_redis_connection();
				if ( $redis && method_exists( $redis, 'info' ) ) {
					$redis_info           = $redis->info();
					$health[ 'redis_info' ] = array(
						'redis_version'     => $redis_info[ 'redis_version' ] ?? 'unknown',
						'used_memory'       => $redis_info[ 'used_memory_human' ] ?? 'unknown',
						'connected_clients' => $redis_info[ 'connected_clients' ] ?? 'unknown',
					);
				}
			} catch (Exception $e) {
				$health[ 'redis_info' ][ 'error' ] = $e->getMessage();
			}
		}

		$overall_status = $health[ 'redis_connected' ] && $health[ 'database_status' ];

		return rest_ensure_response(
			array(
				'success' => $overall_status,
				'status'  => $overall_status ? 'healthy' : 'unhealthy',
				'data'    => $health,
			)
		);
	}

	/**
	 * Clear a queue.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function clear_queue( $request ) {
		$queue_name = $request->get_param( 'name' );

		if ( empty( $queue_name ) ) {
			return new WP_Error(
				'missing_queue_name',
				__( 'Queue name is required.', 'redis-queue-demo' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->queue_manager->clear_queue( $queue_name );

		if ( $result ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => sprintf(
						/* translators: %s: queue name */
						__( 'Queue "%s" cleared successfully.', 'redis-queue-demo' ),
						$queue_name
					),
				)
			);
		} else {
			return new WP_Error(
				'queue_clear_failed',
				__( 'Failed to clear queue.', 'redis-queue-demo' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Check if user has permission to access endpoints.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if user has permission, WP_Error otherwise.
	 */
	public function check_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'redis-queue-demo' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if user has admin permissions for destructive operations.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if user has permission, WP_Error otherwise.
	 */
	public function check_admin_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'redis-queue-demo' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get collection parameters for jobs endpoint.
	 *
	 * @since 1.0.0
	 * @return array Collection parameters.
	 */
	private function get_collection_params() {
		return array(
			'page'     => array(
				'description' => __( 'Current page of the collection.', 'redis-queue-demo' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page' => array(
				'description' => __( 'Maximum number of items to be returned in result set.', 'redis-queue-demo' ),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'status'   => array(
				'description' => __( 'Filter jobs by status.', 'redis-queue-demo' ),
				'type'        => 'string',
				'enum'        => array( 'queued', 'processing', 'completed', 'failed', 'cancelled' ),
			),
			'queue'    => array(
				'description' => __( 'Filter jobs by queue name.', 'redis-queue-demo' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * Get parameters for job creation.
	 *
	 * @since 1.0.0
	 * @return array Job creation parameters.
	 */
	private function get_create_job_params() {
		return array(
			'type'     => array(
				'description' => __( 'Job type.', 'redis-queue-demo' ),
				'type'        => 'string',
				'required'    => true,
				'enum'        => array( 'email', 'image_processing', 'api_sync' ),
			),
			'payload'  => array(
				'description' => __( 'Job payload data.', 'redis-queue-demo' ),
				'type'        => 'object',
				'default'     => array(),
			),
			'priority' => array(
				'description' => __( 'Job priority (lower number = higher priority).', 'redis-queue-demo' ),
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 0,
				'maximum'     => 100,
			),
			'queue'    => array(
				'description' => __( 'Queue name.', 'redis-queue-demo' ),
				'type'        => 'string',
				'default'     => 'default',
			),
		);
	}

	/**
	 * Get parameters for worker trigger.
	 *
	 * @since 1.0.0
	 * @return array Worker trigger parameters.
	 */
	private function get_trigger_worker_params() {
		return array(
			'queues'   => array(
				'description' => __( 'Queue names to process.', 'redis-queue-demo' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'default'     => array( 'default' ),
			),
			'max_jobs' => array(
				'description' => __( 'Maximum number of jobs to process.', 'redis-queue-demo' ),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
			),
		);
	}

	/**
	 * Create a job instance from type and payload.
	 *
	 * @since 1.0.0
	 * @param string $job_type Job type.
	 * @param array  $payload  Job payload.
	 * @return Queue_Job|null Job instance or null on failure.
	 */
	private function create_job_instance( $job_type, $payload ) {
		switch ( $job_type ) {
			case 'email':
				return new Email_Job( $payload );
			case 'image_processing':
				return new Image_Processing_Job( $payload );
			case 'api_sync':
				return new API_Sync_Job( $payload );
			default:
				return null;
		}
	}

	/**
	 * Format job data for API response.
	 *
	 * @since 1.0.0
	 * @param array $job Job data from database.
	 * @return array Formatted job data.
	 */
	private function format_job_response( $job ) {
		$payload = json_decode( $job[ 'payload' ], true );
		$result  = $job[ 'result' ] ? json_decode( $job[ 'result' ], true ) : null;

		return array(
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
		);
	}

	/**
	 * Check database health.
	 *
	 * @since 1.0.0
	 * @return bool True if database is healthy.
	 */
	private function check_database_health() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$table_name
			)
		);

		return ! empty( $table_exists );
	}
}