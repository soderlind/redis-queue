<?php
namespace Soderlind\RedisQueue\Core;

use Soderlind\RedisQueue\Update\GitHub_Plugin_Updater;

/**
 * Main Redis Queue plugin class.
 */
final class Redis_Queue {
	private static $instance = null;

	/** @var Redis_Queue_Manager|null */
	public $queue_manager = null;
	/** @var Job_Processor|null */
	public $job_processor = null;
	/** @var \Soderlind\RedisQueue\API\REST_Controller|null */
	public $rest_controller = null;
	/** @var \Soderlind\RedisQueue\Admin\Admin_Interface|null */
	public $admin_interface = null;

	/**
	 * Get plugin singleton instance.
	 * 
	 * @return self Plugin instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 * Initializes GitHub updater and WordPress hooks.
	 */
	public function __construct() {
		// Initialize GitHub updater for automatic plugin updates.
		GitHub_Plugin_Updater::create_with_assets(
			'https://github.com/soderlind/redis-queue',
			defined( 'REDIS_QUEUE_PLUGIN_FILE' ) ? REDIS_QUEUE_PLUGIN_FILE : __FILE__,
			'redis-queue',
			'/redis-queue\\.zip/',
			'main'
		);
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 * Registers activation, deactivation, and initialization hooks.
	 */
	private function init_hooks(): void {
		\register_activation_hook( REDIS_QUEUE_PLUGIN_FILE, [ $this, 'activate' ] );
		\register_deactivation_hook( REDIS_QUEUE_PLUGIN_FILE, [ $this, 'deactivate' ] );
		\add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		\add_action( 'init', [ $this, 'init' ] );
		\add_action( 'rest_api_init', [ $this, 'init_rest_api' ] );
	}

	/**
	 * Initialize plugin components.
	 * Loads dependencies and creates component instances.
	 * Fires 'redis_queue_init' action hook.
	 */
	public function init(): void {
		$this->load_dependencies();
		$this->init_components();
		\do_action( 'redis_queue_init', $this );
	}

	/**
	 * Load plugin dependencies.
	 * All dependencies are autoloaded via Composer.
	 */
	private function load_dependencies(): void {
		// Autoloaded via Composer.
	}

	/**
	 * Initialize plugin components.
	 * Creates queue manager, job processor, REST controller, and admin interface.
	 */
	private function init_components(): void {
		$this->queue_manager   = new Redis_Queue_Manager();
		$this->job_processor   = new Job_Processor( $this->queue_manager );
		$this->rest_controller = new \Soderlind\RedisQueue\API\REST_Controller( $this->queue_manager, $this->job_processor );
		
		// Initialize admin interface only in admin context.
		if ( \is_admin() ) {
			$this->admin_interface = new \Soderlind\RedisQueue\Admin\Admin_Interface( $this->queue_manager, $this->job_processor );
			if ( method_exists( $this->admin_interface, 'init' ) ) {
				$this->admin_interface->init();
			}
		}
	}

	/**
	 * Initialize REST API routes.
	 * Registers REST API endpoints for queue management.
	 */
	/**
	 * Initialize REST API routes.
	 * Registers REST API endpoints for queue management.
	 */
	public function init_rest_api(): void {
		if ( $this->rest_controller ) {
			$this->rest_controller->register_routes();
		}
	}

	/**
	 * Load plugin text domain for translations.
	 */
	public function load_textdomain(): void {
		\load_plugin_textdomain( 'redis-queue', false, dirname( REDIS_QUEUE_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Plugin activation hook.
	 * Creates database tables, sets default options, and validates requirements.
	 */
	public function activate(): void {
		// Check PHP version requirement.
		if ( \version_compare( PHP_VERSION, REDIS_QUEUE_MIN_PHP, '<' ) ) {
			\deactivate_plugins( REDIS_QUEUE_PLUGIN_BASENAME );
			\wp_die( \esc_html__( 'Redis Queue requires a newer PHP version.', 'redis-queue' ), \esc_html__( 'Plugin Activation Error', 'redis-queue' ), [ 'back_link' => true ] );
		}
		
		// Check Redis extension or Predis library availability.
		if ( ! \extension_loaded( 'redis' ) && ! \class_exists( 'Predis\\Client' ) ) {
			\deactivate_plugins( REDIS_QUEUE_PLUGIN_BASENAME );
			\wp_die( \esc_html__( 'Redis Queue requires either the Redis extension or Predis library.', 'redis-queue' ), \esc_html__( 'Plugin Activation Error', 'redis-queue' ), [ 'back_link' => true ] );
		}
		
		$this->create_tables();
		$this->set_default_options();
		\flush_rewrite_rules();
		\do_action( 'redis_queue_activate' );
	}

	/**
	 * Plugin deactivation hook.
	 * Clears scheduled cron jobs and flushes rewrite rules.
	 */
	public function deactivate(): void {
		\wp_clear_scheduled_hook( 'redis_queue_process_jobs' );
		\flush_rewrite_rules();
		\do_action( 'redis_queue_deactivate' );
	}

	/**
	 * Create plugin database tables.
	 * Creates the jobs table with proper indexes for performance.
	 */
	/**
	 * Create plugin database tables.
	 * Creates the jobs table with proper indexes for performance.
	 */
	private function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'redis_queue_jobs';
		
		// Create jobs table with comprehensive schema for tracking job lifecycle.
		$sql             = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(255) NOT NULL,
            job_type varchar(100) NOT NULL,
            queue_name varchar(100) NOT NULL DEFAULT 'default',
            priority int(11) NOT NULL DEFAULT 50,
            status varchar(20) NOT NULL DEFAULT 'queued',
            payload longtext,
            result longtext,
            attempts int(11) NOT NULL DEFAULT 0,
            max_attempts int(11) NOT NULL DEFAULT 3,
            timeout int(11) NOT NULL DEFAULT 300,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            failed_at datetime NULL,
            error_message text,
            PRIMARY KEY (id),
            UNIQUE KEY job_id (job_id),
            KEY status (status),
            KEY queue_name (queue_name),
            KEY priority (priority),
            KEY created_at (created_at)
        ) $charset_collate;";
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );
	}

	/**
	 * Set default plugin options.
	 * Initializes default configuration for Redis connection and queue settings.
	 */
	private function set_default_options(): void {
		$default_options = [
			'redis_host'             => '127.0.0.1',
			'redis_port'             => 6379,
			'redis_password'         => '',
			'redis_database'         => 0,
			'default_queue'          => 'default',
			'max_jobs_per_run'       => 10,
			'worker_timeout'         => 300,
			'max_retries'            => 3,
			'retry_backoff'          => [ 60, 300, 900 ],
			'enable_logging'         => true,
			'cleanup_completed_jobs' => true,
			'cleanup_after_days'     => 7,
		];
		
		// Add options only if they don't already exist.
		foreach ( $default_options as $option => $value ) {
			$option_name = 'redis_queue_' . $option;
			if ( false === \get_option( $option_name ) ) {
				\add_option( $option_name, $value );
			}
		}
	}

	/**
	 * Get plugin option value.
	 * 
	 * @param string $option  Option name (without redis_queue_ prefix).
	 * @param mixed  $default Default value if option doesn't exist.
	 * @return mixed Option value or default.
	 */
	public function get_option( $option, $default = null ) {
		return \get_option( 'redis_queue_' . $option, $default );
	}

	/**
	 * Update plugin option value.
	 * 
	 * @param string $option Option name (without redis_queue_ prefix).
	 * @param mixed  $value  New option value.
	 * @return bool True if updated, false otherwise.
	 */
	public function update_option( $option, $value ) {
		return \update_option( 'redis_queue_' . $option, $value );
	}

	/**
	 * Get queue manager instance.
	 * 
	 * @return Redis_Queue_Manager|null Queue manager instance.
	 */
	public function get_queue_manager() {
		return $this->queue_manager;
	}

	/**
	 * Get job processor instance.
	 * 
	 * @return Job_Processor|null Job processor instance.
	 */
	public function get_job_processor() {
		return $this->job_processor;
	}

	/**
	 * Enqueue a job to the Redis queue.
	 * 
	 * @param string $job_type Job type identifier (email, image_processing, api_sync, or custom).
	 * @param array  $payload  Job-specific data.
	 * @param array  $options  Optional settings: priority, queue, delay.
	 * @return string|false Job ID on success, false on failure.
	 */
	public function enqueue_job( $job_type, $payload = [], $options = [] ) {
		if ( ! $this->queue_manager ) {
			return false;
		}
		
		// Create job instance based on type.
		$job = $this->create_job_instance( $job_type, $payload );
		if ( ! $job ) {
			return false;
		}
		
		// Apply optional settings.
		if ( isset( $options[ 'priority' ] ) ) {
			$job->set_priority( (int) $options[ 'priority' ] );
		}
		if ( isset( $options[ 'queue' ] ) ) {
			$job->set_queue_name( $options[ 'queue' ] );
		}
		if ( isset( $options[ 'delay' ] ) ) {
			$job->set_delay_until( time() + (int) $options[ 'delay' ] );
		}
		
		return $this->queue_manager->enqueue( $job );
	}

	/**
	 * Create job instance based on job type.
	 * 
	 * @param string $job_type Job type identifier.
	 * @param array  $payload  Job payload data.
	 * @return \Soderlind\RedisQueue\Contracts\Queue_Job|null Job instance or null if type unknown.
	 */
	private function create_job_instance( $job_type, $payload ) {
		// Handle built-in job types.
		switch ( $job_type ) {
			case 'email':
				return new \Soderlind\RedisQueue\Jobs\Email_Job( $payload );
			case 'image_processing':
				return new \Soderlind\RedisQueue\Jobs\Image_Processing_Job( $payload );
			case 'api_sync':
				return new \Soderlind\RedisQueue\Jobs\API_Sync_Job( $payload );
			default:
				// Allow custom job types via filter.
				return \apply_filters( 'redis_queue_create_job', null, $job_type, $payload );
		}
	}
}
