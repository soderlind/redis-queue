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
		$stats  = $this->queue_manager->get_queue_stats();
		$health = $this->get_system_health();
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
					<div class="stat-number" id="queued-jobs"><?php echo esc_html( $stats[ 'queued' ] ?? 0 ); ?></div>
				</div>
				<div class="stat-box">
					<h3><?php esc_html_e( 'Processing', 'redis-queue-demo' ); ?></h3>
					<div class="stat-number" id="processing-jobs"><?php echo esc_html( $stats[ 'processing' ] ?? 0 ); ?></div>
				</div>
				<div class="stat-box">
					<h3><?php esc_html_e( 'Completed', 'redis-queue-demo' ); ?></h3>
					<div class="stat-number" id="completed-jobs"><?php echo esc_html( $stats[ 'completed' ] ?? 0 ); ?></div>
				</div>
				<div class="stat-box">
					<h3><?php esc_html_e( 'Failed', 'redis-queue-demo' ); ?></h3>
					<div class="stat-number" id="failed-jobs"><?php echo esc_html( $stats[ 'failed' ] ?? 0 ); ?></div>
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
				</div>
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
								<th><label for="email-type"><?php esc_html_e( 'Email Type:', 'redis-queue-demo' ); ?></label>
								</th>
								<td>
									<select id="email-type" name="email_type">
										<option value="single"><?php esc_html_e( 'Single Email', 'redis-queue-demo' ); ?>
										</option>
										<option value="bulk"><?php esc_html_e( 'Bulk Email', 'redis-queue-demo' ); ?></option>
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
								<th><label for="email-subject"><?php esc_html_e( 'Subject:', 'redis-queue-demo' ); ?></label>
								</th>
								<td><input type="text" id="email-subject" name="subject" value="Test Email from Redis Queue"
										class="regular-text"></td>
							</tr>
							<tr>
								<th><label for="email-message"><?php esc_html_e( 'Message:', 'redis-queue-demo' ); ?></label>
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
										<option value="optimize"><?php esc_html_e( 'Optimize Image', 'redis-queue-demo' ); ?>
										</option>
										<option value="watermark"><?php esc_html_e( 'Add Watermark', 'redis-queue-demo' ); ?>
										</option>
									</select>
								</td>
							</tr>
							<tr>
								<th><label
										for="attachment-id"><?php esc_html_e( 'Attachment ID:', 'redis-queue-demo' ); ?></label>
								</th>
								<td>
									<input type="number" id="attachment-id" name="attachment_id" value="1" class="small-text">
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
								<th><label for="api-operation"><?php esc_html_e( 'Operation:', 'redis-queue-demo' ); ?></label>
								</th>
								<td>
									<select id="api-operation" name="operation">
										<option value="social_media_post">
											<?php esc_html_e( 'Social Media Post', 'redis-queue-demo' ); ?>
										</option>
										<option value="crm_sync"><?php esc_html_e( 'CRM Sync', 'redis-queue-demo' ); ?></option>
										<option value="webhook"><?php esc_html_e( 'Webhook', 'redis-queue-demo' ); ?></option>
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
		}

		$options  = get_option( 'redis_queue_settings', array() );
		$defaults = array(
			'redis_host'     => '127.0.0.1',
			'redis_port'     => 6379,
			'redis_database' => 0,
			'redis_password' => '',
			'worker_timeout' => 30,
			'max_retries'    => 3,
			'retry_delay'    => 60,
			'batch_size'     => 10,
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
							<input type="number" name="redis_port" value="<?php echo esc_attr( $options[ 'redis_port' ] ); ?>"
								class="small-text">
							<p class="description"><?php esc_html_e( 'Redis server port number.', 'redis-queue-demo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Redis Database', 'redis-queue-demo' ); ?></th>
						<td>
							<input type="number" name="redis_database"
								value="<?php echo esc_attr( $options[ 'redis_database' ] ); ?>" class="small-text" min="0"
								max="15">
							<p class="description"><?php esc_html_e( 'Redis database number (0-15).', 'redis-queue-demo' ); ?>
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
							<input type="number" name="max_retries" value="<?php echo esc_attr( $options[ 'max_retries' ] ); ?>"
								class="small-text" min="0" max="10">
							<p class="description">
								<?php esc_html_e( 'Maximum number of retry attempts for failed jobs.', 'redis-queue-demo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Retry Delay', 'redis-queue-demo' ); ?></th>
						<td>
							<input type="number" name="retry_delay" value="<?php echo esc_attr( $options[ 'retry_delay' ] ); ?>"
								class="small-text" min="10" max="3600">
							<p class="description">
								<?php esc_html_e( 'Base delay in seconds before retrying failed jobs.', 'redis-queue-demo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Batch Size', 'redis-queue-demo' ); ?></th>
						<td>
							<input type="number" name="batch_size" value="<?php echo esc_attr( $options[ 'batch_size' ] ); ?>"
								class="small-text" min="1" max="100">
							<p class="description">
								<?php esc_html_e( 'Number of jobs to process in a single batch.', 'redis-queue-demo' ); ?>
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
		$settings = array(
			'redis_host'     => sanitize_text_field( $_POST[ 'redis_host' ] ),
			'redis_port'     => intval( $_POST[ 'redis_port' ] ),
			'redis_database' => intval( $_POST[ 'redis_database' ] ),
			'redis_password' => sanitize_text_field( $_POST[ 'redis_password' ] ),
			'worker_timeout' => intval( $_POST[ 'worker_timeout' ] ),
			'max_retries'    => intval( $_POST[ 'max_retries' ] ),
			'retry_delay'    => intval( $_POST[ 'retry_delay' ] ),
			'batch_size'     => intval( $_POST[ 'batch_size' ] ),
		);

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
				$results = redis_queue_process_jobs( array( 'default' ), 10 );
			} else {
				// Fallback to direct instantiation
				$sync_worker = new Sync_Worker( $this->queue_manager, $this->job_processor );
				$results     = $sync_worker->process_jobs( array( 'default' ), 10 );
			}

			wp_send_json_success( $results );
		} catch (Exception $e) {
			wp_send_json_error( 'Worker error: ' . $e->getMessage() );
		} catch (Error $e) {
			wp_send_json_error( 'Fatal error: ' . $e->getMessage() );
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

		$stats = $this->queue_manager->get_queue_stats();
		wp_send_json_success( $stats );
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
}