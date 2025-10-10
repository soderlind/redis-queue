<?php
/**
 * Admin Interface Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface class.
 * 
 * Handles WordPress admin pages and interface.
 *
 * @since 1.0.0
 */
class Admin_Interface {

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
	 * Initialize admin interface.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_redis_queue_trigger_worker', array( $this, 'ajax_trigger_worker' ) );
		add_action( 'wp_ajax_redis_queue_get_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_redis_queue_clear_queue', array( $this, 'ajax_clear_queue' ) );
		add_action( 'wp_ajax_redis_queue_create_test_job', array( $this, 'ajax_create_test_job' ) );
		add_action( 'wp_ajax_redis_queue_diagnostics', array( $this, 'ajax_diagnostics' ) );
		add_action( 'wp_ajax_redis_queue_debug_test', array( $this, 'ajax_debug_test' ) );
		add_action( 'wp_ajax_redis_queue_reset_stuck_jobs', array( $this, 'ajax_reset_stuck_jobs' ) );
		add_action( 'wp_ajax_redis_queue_purge_jobs', array( $this, 'ajax_purge_jobs' ) );
	}

	/**
	 * Add admin menu.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Redis Queue', 'redis-queue-demo' ),
			__( 'Redis Queue', 'redis-queue-demo' ),
			'manage_options',
			'redis-queue-demo',
			array( $this, 'render_dashboard_page' ),
			'dashicons-database-view',
			30
		);

		add_submenu_page(
			'redis-queue-demo',
			__( 'Dashboard', 'redis-queue-demo' ),
			__( 'Dashboard', 'redis-queue-demo' ),
			'manage_options',
			'redis-queue-demo',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'redis-queue-demo',
			__( 'Jobs', 'redis-queue-demo' ),
			__( 'Jobs', 'redis-queue-demo' ),
			'manage_options',
			'redis-queue-jobs',
			array( $this, 'render_jobs_page' )
		);

		add_submenu_page(
			'redis-queue-demo',
			__( 'Test Jobs', 'redis-queue-demo' ),
			__( 'Test Jobs', 'redis-queue-demo' ),
			'manage_options',
			'redis-queue-test',
			array( $this, 'render_test_page' )
		);

		add_submenu_page(
			'redis-queue-demo',
			__( 'Settings', 'redis-queue-demo' ),
			__( 'Settings', 'redis-queue-demo' ),
			'manage_options',
			'redis-queue-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_scripts( $hook_suffix ) {
		// Only enqueue on our admin pages.
		if ( strpos( $hook_suffix, 'redis-queue' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'redis-queue-admin',
			plugin_dir_url( __FILE__ ) . '../assets/admin.js',
			array( 'jquery' ),
			REDIS_QUEUE_DEMO_VERSION,
			true
		);

		wp_localize_script(
			'redis-queue-admin',
			'redisQueueAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'redis_queue_admin' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'restUrl'   => rest_url( 'redis-queue/v1/' ),
				'strings'   => array(
					'processing'      => __( 'Processing...', 'redis-queue-demo' ),
					'success'         => __( 'Success!', 'redis-queue-demo' ),
					'error'           => __( 'Error occurred', 'redis-queue-demo' ),
					'confirmClear'    => __( 'Are you sure you want to clear this queue?', 'redis-queue-demo' ),
					'workerTriggered' => __( 'Worker triggered successfully', 'redis-queue-demo' ),
					'queueCleared'    => __( 'Queue cleared successfully', 'redis-queue-demo' ),
				),
			)
		);

		wp_enqueue_style(
			'redis-queue-admin',
			plugin_dir_url( __FILE__ ) . '../assets/admin.css',
			array(),
			REDIS_QUEUE_DEMO_VERSION
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @since 1.0.0
	 */
	public function render_dashboard_page() {
		$stats      = $this->queue_manager->get_queue_stats();
		$flat_stats = $this->flatten_stats( $stats );
		$health     = $this->get_system_health();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redis Queue Dashboard', 'redis-queue-demo' ); ?></h1>

			<!-- Health Status -->
			<div class="redis-queue-health-status <?php echo $health[ 'overall' ] ? 'healthy' : 'unhealthy'; ?>">
				<h2>
					<?php esc_html_e( 'System Health', 'redis-queue-demo' ); ?>
					<span class="status-indicator"></span>
				</h2>
				<div class="health-details">
					<div class="health-item">
						<span class="label"><?php esc_html_e( 'Redis Connection:', 'redis-queue-demo' ); ?></span>
						<span class="value <?php echo $health[ 'redis' ] ? 'connected' : 'disconnected'; ?>">
							<?php echo $health[ 'redis' ] ? esc_html__( 'Connected', 'redis-queue-demo' ) : esc_html__( 'Disconnected', 'redis-queue-demo' ); ?>
						</span>
					</div>
					<div class="health-item">
						<span class="label"><?php esc_html_e( 'Database:', 'redis-queue-demo' ); ?></span>
						<span class="value <?php echo $health[ 'database' ] ? 'ok' : 'error'; ?>">
							<?php echo $health[ 'database' ] ? esc_html__( 'OK', 'redis-queue-demo' ) : esc_html__( 'Error', 'redis-queue-demo' ); ?>
						</span>
					</div>
				</div>
			</div>

			<!-- Quick Stats -->
			<div class="redis-queue-stats">
				<div class="stat-box">
					<h3><?php esc_html_e( 'Queued Jobs', 'redis-queue-demo' ); ?></h3>
					<div class="stat-number" id="queued-jobs"><?php echo esc_html( $flat_stats[ 'queued' ] ?? 0 ); ?></div>
				</div>
				<div class="stat-box">
					<h3><?php esc_html_e( 'Processing', 'redis-queue-demo' ); ?></h3>
					<div class="stat-number" id="processing-jobs"><?php echo esc_html( $flat_stats[ 'processing' ] ?? 0 ); ?>
					</div>
				</div>
				<div class="stat-box">
					<h3><?php esc_html_e( 'Completed', 'redis-queue-demo' ); ?></h3>
					<div class="stat-number" id="completed-jobs"><?php echo esc_html( $flat_stats[ 'completed' ] ?? 0 ); ?>
					</div>
				</div>
				<div class="stat-box">
					<h3><?php esc_html_e( 'Failed', 'redis-queue-demo' ); ?></h3>
					<div class="stat-number" id="failed-jobs"><?php echo esc_html( $flat_stats[ 'failed' ] ?? 0 ); ?></div>
				</div>
			</div>

			<!-- Worker Controls -->
			<div class="redis-queue-controls">
				<h2><?php esc_html_e( 'Worker Controls', 'redis-queue-demo' ); ?></h2>
				<div class="control-buttons">
					<button type="button" class="button button-primary" id="trigger-worker">
						<?php esc_html_e( 'Trigger Worker', 'redis-queue-demo' ); ?>
					</button>
					<button type="button" class="button" id="refresh-stats">
						<?php esc_html_e( 'Refresh Stats', 'redis-queue-demo' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="run-diagnostics">
						<?php esc_html_e( 'Run Diagnostics', 'redis-queue-demo' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="debug-test">
						<?php esc_html_e( 'Full Debug Test', 'redis-queue-demo' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="reset-stuck-jobs">
						<?php esc_html_e( 'Reset Stuck Jobs', 'redis-queue-demo' ); ?>
					</button>
				</div>
				<div class="purge-buttons" style="margin-top:10px;">
					<!-- Purge buttons moved to Jobs page -->
					<div id="diagnostics-result" style="margin-top: 15px;"></div>
					<div id="debug-test-result" style="margin-top: 15px;"></div>
					<div id="reset-result" style="margin-top: 15px;"></div>
				</div>

				<!-- Queue Overview -->
				<div class="redis-queue-overview">
					<h2><?php esc_html_e( 'Queue Overview', 'redis-queue-demo' ); ?></h2>
					<div id="queue-stats-container">
						<!-- Stats will be loaded via AJAX -->
					</div>
				</div>
			</div>
			<?php
	}

	/**
	 * Render jobs page.
	 *
	 * @since 1.0.0
	 */
	public function render_jobs_page() {
		global $wpdb;

		$per_page      = 20;
		$current_page  = isset( $_GET[ 'paged' ] ) ? max( 1, intval( $_GET[ 'paged' ] ) ) : 1;
		$status_filter = isset( $_GET[ 'status' ] ) ? sanitize_text_field( $_GET[ 'status' ] ) : '';

		$table_name = $wpdb->prefix . 'redis_queue_jobs';
		$offset     = ( $current_page - 1 ) * $per_page;

		// Build query.
		$where_conditions = array();
		$prepare_values   = array();

		if ( $status_filter ) {
			$where_conditions[] = 'status = %s';
			$prepare_values[]   = $status_filter;
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
		$total_jobs = (int) $wpdb->get_var( $count_query );

		// Get jobs.
		$jobs_query       = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$prepare_values[] = $per_page;
		$prepare_values[] = $offset;

		$jobs = $wpdb->get_results(
			$wpdb->prepare( $jobs_query, ...$prepare_values ),
			ARRAY_A
		);

		$total_pages = ceil( $total_jobs / $per_page );
		?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Queue Jobs', 'redis-queue-demo' ); ?></h1>
				<div class="purge-buttons" style="margin-top:10px; margin-bottom:15px;">
					<strong><?php esc_html_e( 'Purge Jobs:', 'redis-queue-demo' ); ?></strong>
					<label for="purge-days" style="margin-left:8px;">
						<?php esc_html_e( 'Older Than (days):', 'redis-queue-demo' ); ?>
						<input type="number" id="purge-days" value="7" min="1" style="width:70px;" />
					</label>
					<button type="button" class="button" data-purge-scope="completed"
						id="purge-completed-jobs"><?php esc_html_e( 'Completed', 'redis-queue-demo' ); ?></button>
					<button type="button" class="button" data-purge-scope="failed"
						id="purge-failed-jobs"><?php esc_html_e( 'Failed', 'redis-queue-demo' ); ?></button>
					<button type="button" class="button" data-purge-scope="older"
						id="purge-older-jobs"><?php esc_html_e( 'Older Than N', 'redis-queue-demo' ); ?></button>
					<button type="button" class="button button-danger" data-purge-scope="all"
						id="purge-all-jobs"><?php esc_html_e( 'All (Danger)', 'redis-queue-demo' ); ?></button>
					<div id="purge-result" style="margin-top:10px;"></div>
				</div>

				<!-- Filters -->
				<div class="tablenav top">
					<form method="get" action="">
						<input type="hidden" name="page" value="redis-queue-jobs">
						<select name="status">
							<option value=""><?php esc_html_e( 'All Statuses', 'redis-queue-demo' ); ?></option>
							<option value="queued" <?php selected( $status_filter, 'queued' ); ?>>
								<?php esc_html_e( 'Queued', 'redis-queue-demo' ); ?>
							</option>
							<option value="processing" <?php selected( $status_filter, 'processing' ); ?>>
								<?php esc_html_e( 'Processing', 'redis-queue-demo' ); ?>
							</option>
							<option value="completed" <?php selected( $status_filter, 'completed' ); ?>>
								<?php esc_html_e( 'Completed', 'redis-queue-demo' ); ?>
							</option>
							<option value="failed" <?php selected( $status_filter, 'failed' ); ?>>
								<?php esc_html_e( 'Failed', 'redis-queue-demo' ); ?>
							</option>
							<option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>>
								<?php esc_html_e( 'Cancelled', 'redis-queue-demo' ); ?>
							</option>
						</select>
						<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'redis-queue-demo' ); ?>">
					</form>
				</div>

				<!-- Jobs Table -->
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Job ID', 'redis-queue-demo' ); ?></th>
							<th><?php esc_html_e( 'Type', 'redis-queue-demo' ); ?></th>
							<th><?php esc_html_e( 'Queue', 'redis-queue-demo' ); ?></th>
							<th><?php esc_html_e( 'Status', 'redis-queue-demo' ); ?></th>
							<th><?php esc_html_e( 'Priority', 'redis-queue-demo' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'redis-queue-demo' ); ?></th>
							<th><?php esc_html_e( 'Created', 'redis-queue-demo' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'redis-queue-demo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $jobs ) ) : ?>
							<tr>
								<td colspan="8"><?php esc_html_e( 'No jobs found.', 'redis-queue-demo' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $jobs as $job ) : ?>
								<tr>
									<td><?php echo esc_html( substr( $job[ 'job_id' ], 0, 8 ) . '...' ); ?></td>
									<td><?php echo esc_html( $job[ 'job_type' ] ); ?></td>
									<td><?php echo esc_html( $job[ 'queue_name' ] ); ?></td>
									<td>
										<span class="status-badge status-<?php echo esc_attr( $job[ 'status' ] ); ?>">
											<?php echo esc_html( ucfirst( $job[ 'status' ] ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $job[ 'priority' ] ); ?></td>
									<td><?php echo esc_html( $job[ 'attempts' ] . '/' . $job[ 'max_attempts' ] ); ?></td>
									<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $job[ 'created_at' ] ) ); ?></td>
									<td>
										<a href="#" class="view-job" data-job-id="<?php echo esc_attr( $job[ 'job_id' ] ); ?>">
											<?php esc_html_e( 'View', 'redis-queue-demo' ); ?>
										</a>
										<?php if ( in_array( $job[ 'status' ], array( 'queued', 'failed' ), true ) ) : ?>
											| <a href="#" class="cancel-job" data-job-id="<?php echo esc_attr( $job[ 'job_id' ] ); ?>">
												<?php esc_html_e( 'Cancel', 'redis-queue-demo' ); ?>
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<!-- Pagination -->
				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<?php
						echo paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => __( '&laquo; Previous', 'redis-queue-demo' ),
								'next_text' => __( 'Next &raquo;', 'redis-queue-demo' ),
								'total'     => $total_pages,
								'current'   => $current_page,
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>
			<?php
	}

	/**
	 * Render test page.
	 *
	 * @since 1.0.0
	 */
	public function render_test_page() {
		?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Test Jobs', 'redis-queue-demo' ); ?></h1>
				<p><?php esc_html_e( 'Create test jobs to verify the queue system is working correctly.', 'redis-queue-demo' ); ?>
				</p>
				<?php wp_nonce_field( 'wp_rest', '_wpnonce' ); ?>

				<div class="redis-queue-test-forms">
					<!-- Email Job Test -->
					<div class="test-form-section">
						<h2><?php esc_html_e( 'Test Email Job', 'redis-queue-demo' ); ?></h2>
						<form id="test-email-job" class="test-job-form">
							<table class="form-table">
								<tr>
									<th><label
											for="email-type"><?php esc_html_e( 'Email Type:', 'redis-queue-demo' ); ?></label>
									</th>
									<td>
										<select id="email-type" name="email_type">
											<option value="single"><?php esc_html_e( 'Single Email', 'redis-queue-demo' ); ?>
											</option>
											<option value="bulk"><?php esc_html_e( 'Bulk Email', 'redis-queue-demo' ); ?>
											</option>
											<option value="newsletter"><?php esc_html_e( 'Newsletter', 'redis-queue-demo' ); ?>
											</option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="email-to"><?php esc_html_e( 'To:', 'redis-queue-demo' ); ?></label></th>
									<td><input type="text" id="email-to" name="to"
											value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
									</td>
								</tr>
								<tr>
									<th><label
											for="email-subject"><?php esc_html_e( 'Subject:', 'redis-queue-demo' ); ?></label>
									</th>
									<td><input type="text" id="email-subject" name="subject" value="Test Email from Redis Queue"
											class="regular-text"></td>
								</tr>
								<tr>
									<th><label
											for="email-message"><?php esc_html_e( 'Message:', 'redis-queue-demo' ); ?></label>
									</th>
									<td><textarea id="email-message" name="message" class="large-text"
											rows="4">This is a test email sent through the Redis queue system.</textarea></td>
								</tr>
							</table>
							<button type="submit"
								class="button button-primary"><?php esc_html_e( 'Queue Email Job', 'redis-queue-demo' ); ?></button>
						</form>
					</div>

					<!-- Image Processing Job Test -->
					<div class="test-form-section">
						<h2><?php esc_html_e( 'Test Image Processing Job', 'redis-queue-demo' ); ?></h2>
						<form id="test-image-job" class="test-job-form">
							<table class="form-table">
								<tr>
									<th><label
											for="image-operation"><?php esc_html_e( 'Operation:', 'redis-queue-demo' ); ?></label>
									</th>
									<td>
										<select id="image-operation" name="operation">
											<option value="thumbnail">
												<?php esc_html_e( 'Generate Thumbnails', 'redis-queue-demo' ); ?>
											</option>
											<option value="optimize">
												<?php esc_html_e( 'Optimize Image', 'redis-queue-demo' ); ?>
											</option>
											<option value="watermark">
												<?php esc_html_e( 'Add Watermark', 'redis-queue-demo' ); ?>
											</option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label
											for="attachment-id"><?php esc_html_e( 'Attachment ID:', 'redis-queue-demo' ); ?></label>
									</th>
									<td>
										<input type="number" id="attachment-id" name="attachment_id" value="1"
											class="small-text">
										<p class="description">
											<?php esc_html_e( 'Enter a valid attachment ID from your media library.', 'redis-queue-demo' ); ?>
										</p>
									</td>
								</tr>
							</table>
							<button type="submit"
								class="button button-primary"><?php esc_html_e( 'Queue Image Job', 'redis-queue-demo' ); ?></button>
						</form>
					</div>

					<!-- API Sync Job Test -->
					<div class="test-form-section">
						<h2><?php esc_html_e( 'Test API Sync Job', 'redis-queue-demo' ); ?></h2>
						<form id="test-api-job" class="test-job-form">
							<table class="form-table">
								<tr>
									<th><label
											for="api-operation"><?php esc_html_e( 'Operation:', 'redis-queue-demo' ); ?></label>
									</th>
									<td>
										<select id="api-operation" name="operation">
											<option value="social_media_post">
												<?php esc_html_e( 'Social Media Post', 'redis-queue-demo' ); ?>
											</option>
											<option value="crm_sync"><?php esc_html_e( 'CRM Sync', 'redis-queue-demo' ); ?>
											</option>
											<option value="webhook"><?php esc_html_e( 'Webhook', 'redis-queue-demo' ); ?>
											</option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="api-url"><?php esc_html_e( 'API URL:', 'redis-queue-demo' ); ?></label></th>
									<td><input type="url" id="api-url" name="api_url" value="https://httpbin.org/post"
											class="regular-text"></td>
								</tr>
								<tr>
									<th><label for="api-data"><?php esc_html_e( 'Data (JSON):', 'redis-queue-demo' ); ?></label>
									</th>
									<td><textarea id="api-data" name="data" class="large-text"
											rows="4">{"message": "Test API sync from Redis Queue"}</textarea></td>
								</tr>
							</table>
							<button type="submit"
								class="button button-primary"><?php esc_html_e( 'Queue API Job', 'redis-queue-demo' ); ?></button>
						</form>
					</div>
				</div>

				<div id="test-results" class="test-results" style="display: none;">
					<h3><?php esc_html_e( 'Test Results', 'redis-queue-demo' ); ?></h3>
					<div id="test-output"></div>
				</div>
			</div>
			<?php
	}

	/**
	 * Render settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page() {
		if ( isset( $_POST[ 'submit' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'redis_queue_settings' ) ) {
			$this->save_settings();
		} elseif ( isset( $_POST[ 'generate_api_token' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'redis_queue_settings' ) ) {
			// Generate token then save other settings.
			$_POST[ '__generate_api_token' ] = 1; // Internal flag consumed in save_settings().
			$this->save_settings();
		} elseif ( isset( $_POST[ 'clear_api_token' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'redis_queue_settings' ) ) {
			$_POST[ '__clear_api_token' ] = 1; // Internal flag.
			$this->save_settings();
		}

		$options  = get_option( 'redis_queue_settings', array() );
		$defaults = array(
			'redis_host'             => '127.0.0.1',
			'redis_port'             => 6379,
			'redis_database'         => 0,
			'redis_password'         => '',
			'worker_timeout'         => 30,
			'max_retries'            => 3,
			'retry_delay'            => 60,
			'batch_size'             => 10,
			'api_token'              => '',
			'api_token_scope'        => 'worker',
			'rate_limit_per_minute'  => 60,
			'enable_request_logging' => 0,
			'log_rotate_size_kb'     => 256,
			'log_max_files'          => 5,
		);
		$options  = wp_parse_args( $options, $defaults );
		?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Redis Queue Settings', 'redis-queue-demo' ); ?></h1>

				<form method="post" action="">
					<?php wp_nonce_field( 'redis_queue_settings' ); ?>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Redis Host', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="text" name="redis_host" value="<?php echo esc_attr( $options[ 'redis_host' ] ); ?>"
									class="regular-text">
								<p class="description">
									<?php esc_html_e( 'Redis server hostname or IP address.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Redis Port', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="number" name="redis_port"
									value="<?php echo esc_attr( $options[ 'redis_port' ] ); ?>" class="small-text">
								<p class="description"><?php esc_html_e( 'Redis server port number.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Redis Database', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="number" name="redis_database"
									value="<?php echo esc_attr( $options[ 'redis_database' ] ); ?>" class="small-text" min="0"
									max="15">
								<p class="description">
									<?php esc_html_e( 'Redis database number (0-15).', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Redis Password', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="password" name="redis_password"
									value="<?php echo esc_attr( $options[ 'redis_password' ] ); ?>" class="regular-text">
								<p class="description">
									<?php esc_html_e( 'Redis server password (leave empty if no password).', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Worker Timeout', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="number" name="worker_timeout"
									value="<?php echo esc_attr( $options[ 'worker_timeout' ] ); ?>" class="small-text" min="5"
									max="300">
								<p class="description">
									<?php esc_html_e( 'Maximum time in seconds for job execution.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Max Retries', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="number" name="max_retries"
									value="<?php echo esc_attr( $options[ 'max_retries' ] ); ?>" class="small-text" min="0"
									max="10">
								<p class="description">
									<?php esc_html_e( 'Maximum number of retry attempts for failed jobs.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Retry Delay', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="number" name="retry_delay"
									value="<?php echo esc_attr( $options[ 'retry_delay' ] ); ?>" class="small-text" min="10"
									max="3600">
								<p class="description">
									<?php esc_html_e( 'Base delay in seconds before retrying failed jobs.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Batch Size', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="number" name="batch_size"
									value="<?php echo esc_attr( $options[ 'batch_size' ] ); ?>" class="small-text" min="1"
									max="100">
								<p class="description">
									<?php esc_html_e( 'Number of jobs to process in a single batch.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'API Token', 'redis-queue-demo' ); ?></th>
							<td>
								<?php if ( ! empty( $options[ 'api_token' ] ) ) : ?>
									<code
										style="display:inline-block; padding:2px 4px; background:#f0f0f0; user-select:all; max-width:500px; overflow-wrap:anywhere;"><?php echo esc_html( $options[ 'api_token' ] ); ?></code><br />
									<label style="display:inline-block; margin-top:6px;">
										<input type="checkbox" name="clear_api_token" value="1">
										<?php esc_html_e( 'Clear token on save', 'redis-queue-demo' ); ?>
									</label>
								<?php else : ?>
									<em><?php esc_html_e( 'No token set.', 'redis-queue-demo' ); ?></em>
								<?php endif; ?>
								<p class="description" style="margin-top:6px;">
									<?php esc_html_e( 'Use this token to authenticate to the plugin REST API without WordPress cookies. Send it as "Authorization: Bearer <token>" or "X-Redis-Queue-Token: <token>". Possession grants the same access as an admin for these endpoints; keep it secret.', 'redis-queue-demo' ); ?>
								</p>
								<p style="margin-top:8px;">
									<button type="submit" name="generate_api_token" class="button">
										<?php echo empty( $options[ 'api_token' ] ) ? esc_html__( 'Generate Token', 'redis-queue-demo' ) : esc_html__( 'Regenerate Token', 'redis-queue-demo' ); ?>
									</button>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Token Scope', 'redis-queue-demo' ); ?></th>
							<td>
								<select name="api_token_scope">
									<option value="worker" <?php selected( $options[ 'api_token_scope' ], 'worker' ); ?>>
										<?php esc_html_e( 'Worker Only (trigger endpoint)', 'redis-queue-demo' ); ?></option>
									<option value="full" <?php selected( $options[ 'api_token_scope' ], 'full' ); ?>>
										<?php esc_html_e( 'Full Access (all endpoints)', 'redis-queue-demo' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Limit what the API token can call. "Worker Only" restricts to /workers/trigger.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Rate Limit (per minute)', 'redis-queue-demo' ); ?></th>
							<td>
								<input type="number" name="rate_limit_per_minute"
									value="<?php echo esc_attr( $options[ 'rate_limit_per_minute' ] ); ?>" class="small-text"
									min="1" max="1000" />
								<p class="description">
									<?php esc_html_e( 'Maximum token-authenticated requests per minute. Applies per token.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Request Logging', 'redis-queue-demo' ); ?></th>
							<td>
								<label><input type="checkbox" name="enable_request_logging" value="1" <?php checked( $options[ 'enable_request_logging' ], 1 ); ?> />
									<?php esc_html_e( 'Enable logging of API requests (namespace: redis-queue/v1)', 'redis-queue-demo' ); ?></label>
								<p class="description">
									<?php esc_html_e( 'Logs contain timestamp, route, status, auth method, and IP. Stored in uploads/redis-queue-demo-logs/', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Log Rotation', 'redis-queue-demo' ); ?></th>
							<td>
								<label><?php esc_html_e( 'Max File Size (KB):', 'redis-queue-demo' ); ?> <input type="number"
										name="log_rotate_size_kb"
										value="<?php echo esc_attr( $options[ 'log_rotate_size_kb' ] ); ?>" class="small-text"
										min="8" max="16384" /></label>
								<label style="margin-left:12px;"><?php esc_html_e( 'Max Files:', 'redis-queue-demo' ); ?> <input
										type="number" name="log_max_files"
										value="<?php echo esc_attr( $options[ 'log_max_files' ] ); ?>" class="small-text" min="1"
										max="50" /></label>
								<p class="description">
									<?php esc_html_e( 'When size exceeded, file is rotated with timestamp. Oldest files removed beyond max files.', 'redis-queue-demo' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button(); ?>
				</form>

				<!-- Connection Test -->
				<div class="redis-queue-connection-test">
					<h2><?php esc_html_e( 'Connection Test', 'redis-queue-demo' ); ?></h2>
					<button type="button" class="button" id="test-redis-connection">
						<?php esc_html_e( 'Test Redis Connection', 'redis-queue-demo' ); ?>
					</button>
					<div id="connection-test-result"></div>
				</div>
			</div>
			<?php
	}

	/**
	 * Save settings.
	 *
	 * @since 1.0.0
	 */
	private function save_settings() {
		$existing = get_option( 'redis_queue_settings', array() );
		$settings = array(
			'redis_host'             => sanitize_text_field( $_POST[ 'redis_host' ] ),
			'redis_port'             => intval( $_POST[ 'redis_port' ] ),
			'redis_database'         => intval( $_POST[ 'redis_database' ] ),
			'redis_password'         => sanitize_text_field( $_POST[ 'redis_password' ] ),
			'worker_timeout'         => intval( $_POST[ 'worker_timeout' ] ),
			'max_retries'            => intval( $_POST[ 'max_retries' ] ),
			'retry_delay'            => intval( $_POST[ 'retry_delay' ] ),
			'batch_size'             => intval( $_POST[ 'batch_size' ] ),
			'api_token'              => isset( $existing[ 'api_token' ] ) ? $existing[ 'api_token' ] : '', // default preserve existing
			'api_token_scope'        => isset( $_POST[ 'api_token_scope' ] ) && in_array( $_POST[ 'api_token_scope' ], array( 'worker', 'full' ), true ) ? $_POST[ 'api_token_scope' ] : ( isset( $existing[ 'api_token_scope' ] ) ? $existing[ 'api_token_scope' ] : 'worker' ),
			'rate_limit_per_minute'  => isset( $_POST[ 'rate_limit_per_minute' ] ) ? max( 1, intval( $_POST[ 'rate_limit_per_minute' ] ) ) : ( isset( $existing[ 'rate_limit_per_minute' ] ) ? intval( $existing[ 'rate_limit_per_minute' ] ) : 60 ),
			'enable_request_logging' => isset( $_POST[ 'enable_request_logging' ] ) ? 1 : 0,
			'log_rotate_size_kb'     => isset( $_POST[ 'log_rotate_size_kb' ] ) ? max( 8, intval( $_POST[ 'log_rotate_size_kb' ] ) ) : ( isset( $existing[ 'log_rotate_size_kb' ] ) ? intval( $existing[ 'log_rotate_size_kb' ] ) : 256 ),
			'log_max_files'          => isset( $_POST[ 'log_max_files' ] ) ? max( 1, intval( $_POST[ 'log_max_files' ] ) ) : ( isset( $existing[ 'log_max_files' ] ) ? intval( $existing[ 'log_max_files' ] ) : 5 ),
		);

		// Handle token clear.
		if ( isset( $_POST[ '__clear_api_token' ] ) || isset( $_POST[ 'clear_api_token' ] ) ) {
			$settings[ 'api_token' ] = '';
		}

		// Handle token generation.
		if ( isset( $_POST[ '__generate_api_token' ] ) || isset( $_POST[ 'generate_api_token' ] ) ) {
			try {
				$settings[ 'api_token' ] = bin2hex( random_bytes( 32 ) ); // 64 hex chars ~256 bits.
			} catch (Exception $e) {
				// Fallback if random_bytes unavailable.
				$settings[ 'api_token' ] = wp_generate_password( 64, false, false );
			}
		}

		update_option( 'redis_queue_settings', $settings );
		add_action( 'admin_notices', array( $this, 'settings_saved_notice' ) );
	}

	/**
	 * Display settings saved notice.
	 *
	 * @since 1.0.0
	 */
	public function settings_saved_notice() {
		?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Settings saved successfully.', 'redis-queue-demo' ); ?></p>
			</div>
			<?php
	}

	/**
	 * AJAX handler for triggering worker.
	 *
	 * @since 1.0.0
	 */
	public function ajax_trigger_worker() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		try {
			// Check if queue manager and job processor are available
			if ( ! $this->queue_manager || ! $this->job_processor ) {
				wp_send_json_error( 'Queue system not initialized' );
				return;
			}

			// Check if Redis connection is available
			if ( ! $this->queue_manager->is_connected() ) {
				wp_send_json_error( 'Redis connection not available' );
				return;
			}

			// Use the helper function to process jobs safely if available
			if ( function_exists( 'redis_queue_process_jobs' ) ) {
				$results = redis_queue_process_jobs( array( 'default', 'email', 'media', 'api' ), 10 );
			} else {
				// Fallback to direct instantiation
				if ( ! class_exists( 'Sync_Worker' ) ) {
					wp_send_json_error( 'Sync_Worker class not available' );
					return;
				}
				$sync_worker = new Sync_Worker( $this->queue_manager, $this->job_processor );
				$results     = $sync_worker->process_jobs( array( 'default', 'email', 'media', 'api' ), 10 );
			}

			// Validate results
			if ( $results === null ) {
				wp_send_json_error( 'Worker returned null results' );
				return;
			}

			if ( ! is_array( $results ) ) {
				wp_send_json_error( 'Worker returned invalid results format' );
				return;
			}

			wp_send_json_success( $results );
		} catch (Exception $e) {
			$error_message = 'Worker error: ';
			if ( $e && method_exists( $e, 'getMessage' ) ) {
				$error_message .= $e->getMessage();
			} else {
				$error_message .= 'Unknown exception occurred';
			}
			wp_send_json_error( $error_message );
		} catch (Error $e) {
			$error_message = 'Fatal error: ';
			if ( $e && method_exists( $e, 'getMessage' ) ) {
				$error_message .= $e->getMessage();
			} else {
				$error_message .= 'Unknown fatal error occurred';
			}
			wp_send_json_error( $error_message );
		} catch (Throwable $e) {
			$error_message = 'Unexpected error: ';
			if ( $e && method_exists( $e, 'getMessage' ) ) {
				$error_message .= $e->getMessage();
			} else {
				$error_message .= 'Unknown throwable occurred';
			}
			wp_send_json_error( $error_message );
		}
	}

	/**
	 * AJAX handler for getting stats.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$stats      = $this->queue_manager->get_queue_stats();
		$flat_stats = $this->flatten_stats( $stats );
		wp_send_json_success( $flat_stats );
	}

	/**
	 * AJAX handler for clearing queue.
	 *
	 * @since 1.0.0
	 */
	public function ajax_clear_queue() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$queue_name = sanitize_text_field( $_POST[ 'queue' ] ?? 'default' );
		$result     = $this->queue_manager->clear_queue( $queue_name );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Queue cleared successfully' ) );
		} else {
			wp_send_json_error( 'Failed to clear queue' );
		}
	}

	/**
	 * AJAX handler for creating test jobs.
	 *
	 * @since 1.0.0
	 */
	public function ajax_create_test_job() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$job_type = sanitize_text_field( $_POST[ 'job_type' ] ?? '' );
		$payload  = $_POST[ 'payload' ] ?? array();

		// Sanitize payload data
		$payload = array_map( 'sanitize_text_field', $payload );

		try {
			// Use the main plugin instance to create the job
			$plugin = redis_queue_demo();
			$job_id = $plugin->enqueue_job( $job_type, $payload, array( 'priority' => 10 ) );

			if ( $job_id ) {
				wp_send_json_success( array(
					'job_id'  => $job_id,
					'message' => 'Job created and enqueued successfully.',
				) );
			} else {
				wp_send_json_error( 'Failed to enqueue job.' );
			}
		} catch (Exception $e) {
			wp_send_json_error( 'Job creation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for diagnostics.
	 *
	 * @since 1.0.0
	 */
	public function ajax_diagnostics() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		try {
			$diagnostics = $this->queue_manager->diagnostic();
			wp_send_json_success( $diagnostics );
		} catch (Exception $e) {
			wp_send_json_error( 'Diagnostic failed: ' . ( $e ? $e->getMessage() : 'Unknown error' ) );
		}
	}

	/**
	 * AJAX handler for comprehensive debug test.
	 *
	 * @since 1.0.0
	 */
	public function ajax_debug_test() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$debug_results = array();

		// Test 1: Plugin initialization
		$plugin                                      = redis_queue_demo();
		$debug_results[ '1. Plugin Initialization' ] = array(
			'Queue Manager' => $plugin->queue_manager ? 'OK' : 'FAILED',
			'Job Processor' => $plugin->job_processor ? 'OK' : 'FAILED',
		);

		// Test 2: Redis connection
		$debug_results[ '2. Redis Connection' ] = array(
			'Connected' => $plugin->queue_manager->is_connected() ? 'YES' : 'NO',
		);

		// Test 3: Redis diagnostics
		$diagnostics                             = $plugin->queue_manager->diagnostic();
		$debug_results[ '3. Redis Diagnostics' ] = array(
			'Test Write'       => isset( $diagnostics[ 'test_write' ] ) && $diagnostics[ 'test_write' ] ? 'OK' : 'FAILED',
			'Test Read'        => isset( $diagnostics[ 'test_read' ] ) && $diagnostics[ 'test_read' ] ? 'OK' : 'FAILED',
			'Queue Prefix'     => $diagnostics[ 'queue_prefix' ] ?? 'unknown',
			'Redis Keys Found' => isset( $diagnostics[ 'redis_keys' ] ) ? count( $diagnostics[ 'redis_keys' ] ) : 0,
			'Keys'             => isset( $diagnostics[ 'redis_keys' ] ) ? implode( ', ', $diagnostics[ 'redis_keys' ] ) : 'none',
		);

		// Test 4: Job creation and processing
		$debug_results[ '4. Job Creation Test' ] = array();
		$job_id                                  = $plugin->enqueue_job( 'email', array(
			'type'    => 'single',
			'to'      => 'test@example.com',
			'subject' => 'Debug Test Email ' . date( 'H:i:s' ),
			'message' => 'This is a debug test email',
		) );

		if ( $job_id ) {
			$debug_results[ '4. Job Creation Test' ][ 'Job Created' ] = 'YES (ID: ' . $job_id . ')';

			// Check Redis keys after creation
			$diagnostics_after                                                      = $plugin->queue_manager->diagnostic();
			$debug_results[ '4. Job Creation Test' ][ 'Redis Keys After Creation' ] = count( $diagnostics_after[ 'redis_keys' ] );
			$debug_results[ '4. Job Creation Test' ][ 'Keys' ]                      = implode( ', ', $diagnostics_after[ 'redis_keys' ] );

			// Try to dequeue
			$dequeued = $plugin->queue_manager->dequeue( array( 'email' ) );
			if ( $dequeued ) {
				$debug_results[ '4. Job Creation Test' ][ 'Job Dequeued' ]      = 'YES';
				$debug_results[ '4. Job Creation Test' ][ 'Dequeued Job ID' ]   = $dequeued[ 'job_id' ] ?? 'unknown';
				$debug_results[ '4. Job Creation Test' ][ 'Dequeued Job Type' ] = $dequeued[ 'job_type' ] ?? 'unknown';
				$debug_results[ '4. Job Creation Test' ][ 'Payload Keys' ]      = isset( $dequeued[ 'payload' ] ) ? implode( ', ', array_keys( $dequeued[ 'payload' ] ) ) : 'none';

				// Attempt to process the dequeued job immediately so it doesn't remain stuck in 'processing'
				try {
					$job_result                                                  = $plugin->job_processor->process_job( $dequeued );
					$debug_results[ '4. Job Creation Test' ][ 'Job Processed' ]  = 'YES';
					$debug_results[ '4. Job Creation Test' ][ 'Job Successful' ] = $job_result->is_successful() ? 'YES' : 'NO';

					// PHPMailer diagnostics (after wp_mail invocation inside job)
					global $phpmailer;
					if ( isset( $phpmailer ) && is_object( $phpmailer ) ) {
						$debug_results[ '4. Job Creation Test' ][ 'PHPMailer Host' ]       = $phpmailer->Host ?? '(unset)';
						$debug_results[ '4. Job Creation Test' ][ 'PHPMailer Port' ]       = $phpmailer->Port ?? '(unset)';
						$debug_results[ '4. Job Creation Test' ][ 'PHPMailer SMTPSecure' ] = $phpmailer->SMTPSecure ?? '(unset)';
						$debug_results[ '4. Job Creation Test' ][ 'PHPMailer SMTPAuth' ]   = isset( $phpmailer->SMTPAuth ) ? ( $phpmailer->SMTPAuth ? 'true' : 'false' ) : '(unset)';
						$debug_results[ '4. Job Creation Test' ][ 'PHPMailer ErrorInfo' ]  = ! empty( $phpmailer->ErrorInfo ) ? $phpmailer->ErrorInfo : '(none)';
						$debug_results[ '4. Job Creation Test' ][ 'PHPMailer From' ]       = $phpmailer->From ?? '(unset)';
						$debug_results[ '4. Job Creation Test' ][ 'PHPMailer FromName' ]   = $phpmailer->FromName ?? '(unset)';
					}

					if ( $job_result->is_successful() ) {
						$data                                                     = $job_result->get_data();
						$debug_results[ '4. Job Creation Test' ][ 'Result Data' ] = is_scalar( $data ) ? (string) $data : wp_json_encode( $data );
					} else {
						$debug_results[ '4. Job Creation Test' ][ 'Error Message' ] = $job_result->get_error_message();
						$debug_results[ '4. Job Creation Test' ][ 'Error Code' ]    = $job_result->get_error_code();
						// Include metadata (e.g., phpmailer_error) for deeper insight
						$metadata = $job_result->get_metadata();
						if ( ! empty( $metadata ) ) {
							$debug_results[ '4. Job Creation Test' ][ 'Job Result Metadata' ] = wp_json_encode( $metadata );
						}
					}
				} catch (Throwable $e) {
					$debug_results[ '4. Job Creation Test' ][ 'Job Processed' ]             = 'NO (Exception)';
					$debug_results[ '4. Job Creation Test' ][ 'Processing Exception' ]      = $e->getMessage();
					$debug_results[ '4. Job Creation Test' ][ 'Processing Exception File' ] = $e->getFile() . ':' . $e->getLine();
				}
			} else {
				$debug_results[ '4. Job Creation Test' ][ 'Job Dequeued' ] = 'NO';
			}
		} else {
			$debug_results[ '4. Job Creation Test' ][ 'Job Created' ] = 'NO';
		}

		// Test 5: Database check
		global $wpdb;
		$table_name                           = $wpdb->prefix . 'redis_queue_jobs';
		$debug_results[ '5. Database Check' ] = array(
			'Table Exists' => $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) == $table_name ? 'YES' : 'NO',
			'Job Count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ),
		);

		// Get recent jobs
		$recent_jobs                                           = $wpdb->get_results( "SELECT job_id, job_type, status, created_at FROM $table_name ORDER BY created_at DESC LIMIT 5", ARRAY_A );
		$debug_results[ '5. Database Check' ][ 'Recent Jobs' ] = array();
		foreach ( $recent_jobs as $job ) {
			$debug_results[ '5. Database Check' ][ 'Recent Jobs' ][] = $job[ 'job_id' ] . ' (' . $job[ 'job_type' ] . ') - ' . $job[ 'status' ] . ' - ' . $job[ 'created_at' ];
		}

		wp_send_json_success( $debug_results );
	}

	/**
	 * AJAX handler to reset stuck jobs.
	 *
	 * @since 1.0.0
	 */
	public function ajax_reset_stuck_jobs() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		try {
			$reset_count = $this->queue_manager->reset_stuck_jobs( 30 );
			wp_send_json_success( array(
				'message' => sprintf( 'Reset %d stuck jobs back to queued status.', $reset_count ),
				'count'   => $reset_count,
			) );
		} catch (Exception $e) {
			wp_send_json_error( 'Failed to reset stuck jobs: ' . ( $e ? $e->getMessage() : 'Unknown error' ) );
		}
	}

	/**
	 * Get system health status.
	 *
	 * @since 1.0.0
	 * @return array Health status.
	 */
	private function get_system_health() {
		return array(
			'redis'    => $this->queue_manager->is_connected(),
			'database' => $this->check_database_health(),
			'overall'  => $this->queue_manager->is_connected() && $this->check_database_health(),
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

		$table_name   = $wpdb->prefix . 'redis_queue_jobs';
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$table_name
			)
		);

		return ! empty( $table_exists );
	}

	/**
	 * Flatten raw stats (which include per-queue arrays and a 'database' summary) into
	 * a simple associative array with top-level queued / processing / completed / failed
	 * counts expected by the dashboard UI and JS refresh logic.
	 *
	 * @since 1.0.0
	 * @param array $stats Raw stats from Redis_Queue_Manager::get_queue_stats().
	 * @return array Flattened stats.
	 */
	private function flatten_stats( $stats ) {
		$flat = array(
			'queued'     => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'total'      => 0,
		);

		// Preferred source: database summary if present.
		if ( isset( $stats[ 'database' ] ) && is_array( $stats[ 'database' ] ) ) {
			$flat[ 'queued' ]     = (int) ( $stats[ 'database' ][ 'queued' ] ?? 0 );
			$flat[ 'processing' ] = (int) ( $stats[ 'database' ][ 'processing' ] ?? 0 );
			$flat[ 'completed' ]  = (int) ( $stats[ 'database' ][ 'completed' ] ?? 0 );
			$flat[ 'failed' ]     = (int) ( $stats[ 'database' ][ 'failed' ] ?? 0 );
			$flat[ 'total' ]      = (int) ( $stats[ 'database' ][ 'total' ] ?? ( $flat[ 'queued' ] + $flat[ 'processing' ] + $flat[ 'completed' ] + $flat[ 'failed' ] ) );
			return $flat;
		}

		// Fallback: derive from per-queue job 'pending' counts if no database summary.
		foreach ( $stats as $queue => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			// We only know 'pending' (queued) from Redis side; other states live in DB.
			if ( isset( $data[ 'pending' ] ) ) {
				$flat[ 'queued' ] += (int) $data[ 'pending' ];
			}
		}
		$flat[ 'total' ] = $flat[ 'queued' ];
		return $flat;
	}

	/**
	 * AJAX handler for purging jobs.
	 *
	 * Supported scopes:
	 * - completed : delete completed jobs
	 * - failed    : delete failed jobs
	 * - older     : delete jobs older than N days (default 7)
	 * - all       : delete ALL jobs (dangerous)
	 *
	 * @since 1.0.0
	 */
	public function ajax_purge_jobs() {
		check_ajax_referer( 'redis_queue_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$scope    = sanitize_text_field( $_POST[ 'scope' ] ?? '' );
		$days_arg = isset( $_POST[ 'days' ] ) ? intval( $_POST[ 'days' ] ) : 7;
		$days     = $days_arg > 0 ? $days_arg : 7;

		if ( ! in_array( $scope, array( 'completed', 'failed', 'older', 'all' ), true ) ) {
			wp_send_json_error( __( 'Invalid purge scope.', 'redis-queue-demo' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'redis_queue_jobs';
		$where = '';
		$args  = array();

		switch ( $scope ) {
			case 'completed':
				$where = "WHERE status = 'completed'";
				break;
			case 'failed':
				$where = "WHERE status = 'failed'";
				break;
			case 'older':
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
				$where = 'WHERE created_at < %s';
				$args[] = $cutoff;
				break;
			case 'all':
				// No where clause (will truncate all rows).
				break;
		}

		// Count first for reporting.
		$count_sql = "SELECT COUNT(*) FROM $table " . $where;
		if ( $args ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
		} else {
			$count = (int) $wpdb->get_var( $count_sql );
		}

		if ( 0 === $count ) {
			wp_send_json_success( array(
				'message' => __( 'No matching jobs to purge.', 'redis-queue-demo' ),
				'count'   => 0,
			) );
		}

		// Perform deletion.
		$delete_sql = "DELETE FROM $table " . $where;
		$deleted    = 0;
		if ( $args ) {
			$prepared = $wpdb->prepare( $delete_sql, ...$args );
			$wpdb->query( $prepared );
			$deleted = $wpdb->rows_affected;
		} else {
			$wpdb->query( $delete_sql );
			$deleted = $wpdb->rows_affected;
		}

		$message = ( 'older' === $scope )
			? sprintf( __( 'Purged %d jobs older than %d days.', 'redis-queue-demo' ), $deleted, $days )
			: sprintf( __( 'Purged %d jobs (scope: %s).', 'redis-queue-demo' ), $deleted, $scope );

		wp_send_json_success( array(
			'message' => $message,
			'count'   => $deleted,
			'scope'   => $scope,
			'days'    => $days,
		) );
	}
}