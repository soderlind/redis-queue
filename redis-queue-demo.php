<?php
/**
 * Plugin Name: Redis Queue Demo
 * Plugin URI: https://github.com/soderlind/redis-queue-demo
 * Description: A comprehensive WordPress plugin demonstrating Redis queues for background job processing. Includes email operations, image processing, API integrations, and more.
 * Version: 1.0.1
 * Author: Per Soderlind
 * Author URI: https://soderlind.no
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: redis-queue-demo
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to: 6.8
 * Requires PHP: 8.3
 * Network: false
 *
 * @package RedisQueueDemo
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'REDIS_QUEUE_DEMO_VERSION', '1.0.1' );
define( 'REDIS_QUEUE_DEMO_PLUGIN_FILE', __FILE__ );
define( 'REDIS_QUEUE_DEMO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REDIS_QUEUE_DEMO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'REDIS_QUEUE_DEMO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main Redis Queue Demo class.
 *
 * @since 1.0.0
 */
final class Redis_Queue_Demo {

	/**
	 * Plugin instance.
	 *
	 * @since 1.0.0
	 * @var Redis_Queue_Demo
	 */
	private static $instance = null;

	/**
	 * Redis Queue Manager instance.
	 *
	 * @since 1.0.0
	 * @var Redis_Queue_Manager
	 */
	public $queue_manager = null;

	/**
	 * Job Processor instance.
	 *
	 * @since 1.0.0
	 * @var Job_Processor
	 */
	public $job_processor = null;

	/**
	 * REST Controller instance.
	 *
	 * @since 1.0.0
	 * @var REST_Controller
	 */
	public $rest_controller = null;

	/**
	 * Admin Interface instance.
	 *
	 * @since 1.0.0
	 * @var Admin_Interface
	 */
	public $admin_interface = null;

	/**
	 * Get plugin instance.
	 *
	 * @since 1.0.0
	 * @return Redis_Queue_Demo
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		//Add plugin updater
		if ( ! class_exists( 'Soderlind\WordPress\GitHub_Plugin_Updater' ) ) {
			require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'includes/class-plugin-updater.php';
		}

		\Soderlind\WordPress\GitHub_Plugin_Updater::create_with_assets(
			'https://github.com/soderlind/redis-queue-demo',
			REDIS_QUEUE_DEMO_PLUGIN_FILE,
			'redis-queue-demo',
			'/redis-queue-demo\.zip/',
			'main'
		);
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		register_activation_hook( REDIS_QUEUE_DEMO_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( REDIS_QUEUE_DEMO_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ) );
	}

	/**
	 * Initialize plugin.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Load dependencies.
		$this->load_dependencies();

		// Initialize components.
		$this->init_components();

		/**
		 * Fires after Redis Queue Demo has been initialized.
		 *
		 * @since 1.0.0
		 * @param Redis_Queue_Demo $plugin The main plugin instance.
		 */
		do_action( 'redis_queue_demo_init', $this );
	}

	/**
	 * Load plugin dependencies.
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies() {


		// Load interfaces.
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'includes/interfaces/interface-queue-job.php';
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'includes/interfaces/interface-job-result.php';

		// Load core classes.
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'includes/class-redis-queue-manager.php';
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'includes/class-job-processor.php';

		// Load job implementations.
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'jobs/abstract-base-job.php';
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'jobs/class-email-job.php';
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'jobs/class-image-processing-job.php';
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'jobs/class-api-sync-job.php';

		// Load workers.
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'workers/class-sync-worker.php';

		// Load API and admin components.
		require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'api/class-rest-controller.php';

		// Load admin classes only in admin area.
		if ( is_admin() ) {
			require_once REDIS_QUEUE_DEMO_PLUGIN_DIR . 'admin/class-admin-interface.php';
		}
	}

	/**
	 * Initialize plugin components.
	 *
	 * @since 1.0.0
	 */
	private function init_components() {
		$this->queue_manager   = new Redis_Queue_Manager();
		$this->job_processor   = new Job_Processor( $this->queue_manager );
		$this->rest_controller = new REST_Controller( $this->queue_manager, $this->job_processor );

		if ( is_admin() && class_exists( 'Admin_Interface' ) ) {
			$this->admin_interface = new Admin_Interface( $this->queue_manager, $this->job_processor );
			$this->admin_interface->init();
		}
	}

	/**
	 * Initialize REST API.
	 *
	 * @since 1.0.0
	 */
	public function init_rest_api() {
		if ( $this->rest_controller ) {
			$this->rest_controller->register_routes();
		}
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'redis-queue-demo',
			false,
			dirname( REDIS_QUEUE_DEMO_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Plugin activation.
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		// Check PHP version.
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			deactivate_plugins( REDIS_QUEUE_DEMO_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Redis Queue Demo requires PHP 8.3 or higher.', 'redis-queue-demo' ),
				esc_html__( 'Plugin Activation Error', 'redis-queue-demo' ),
				array( 'back_link' => true )
			);
		}

		// Check if Redis extension is available.
		if ( ! extension_loaded( 'redis' ) && ! class_exists( 'Predis\Client' ) ) {
			deactivate_plugins( REDIS_QUEUE_DEMO_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Redis Queue Demo requires either the Redis PHP extension or Predis library.', 'redis-queue-demo' ),
				esc_html__( 'Plugin Activation Error', 'redis-queue-demo' ),
				array( 'back_link' => true )
			);
		}

		// Create database tables.
		$this->create_tables();

		// Set default options.
		$this->set_default_options();

		// Flush rewrite rules.
		flush_rewrite_rules();

		/**
		 * Fires on plugin activation.
		 *
		 * @since 1.0.0
		 */
		do_action( 'redis_queue_demo_activate' );
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		// Clear scheduled events.
		wp_clear_scheduled_hook( 'redis_queue_demo_process_jobs' );

		// Flush rewrite rules.
		flush_rewrite_rules();

		/**
		 * Fires on plugin deactivation.
		 *
		 * @since 1.0.0
		 */
		do_action( 'redis_queue_demo_deactivate' );
	}

	/**
	 * Create database tables.
	 *
	 * @since 1.0.0
	 */
	private function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Jobs metadata table.
		$table_name = $wpdb->prefix . 'redis_queue_jobs';

		$sql = "CREATE TABLE $table_name (
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
		dbDelta( $sql );
	}

	/**
	 * Set default plugin options.
	 *
	 * @since 1.0.0
	 */
	private function set_default_options() {
		$default_options = array(
			'redis_host'             => '127.0.0.1',
			'redis_port'             => 6379,
			'redis_password'         => '',
			'redis_database'         => 0,
			'default_queue'          => 'default',
			'max_jobs_per_run'       => 10,
			'worker_timeout'         => 300,
			'max_retries'            => 3,
			'retry_backoff'          => array( 60, 300, 900 ),
			'enable_logging'         => true,
			'cleanup_completed_jobs' => true,
			'cleanup_after_days'     => 7,
		);

		foreach ( $default_options as $option => $value ) {
			$option_name = 'redis_queue_demo_' . $option;
			if ( false === get_option( $option_name ) ) {
				add_option( $option_name, $value );
			}
		}
	}

	/**
	 * Get plugin option.
	 *
	 * @since 1.0.0
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed Option value.
	 */
	public function get_option( $option, $default = null ) {
		return get_option( 'redis_queue_demo_' . $option, $default );
	}

	/**
	 * Update plugin option.
	 *
	 * @since 1.0.0
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return bool Whether the option was updated.
	 */
	public function update_option( $option, $value ) {
		return update_option( 'redis_queue_demo_' . $option, $value );
	}

	/**
	 * Get queue manager instance.
	 *
	 * @since 1.0.0
	 * @return Redis_Queue_Manager|null
	 */
	public function get_queue_manager() {
		return $this->queue_manager;
	}

	/**
	 * Get job processor instance.
	 *
	 * @since 1.0.0
	 * @return Job_Processor|null
	 */
	public function get_job_processor() {
		return $this->job_processor;
	}

	/**
	 * Create and enqueue a job.
	 *
	 * @since 1.0.0
	 * @param string $job_type Job type.
	 * @param array  $payload  Job payload.
	 * @param array  $options  Job options (priority, queue, etc.).
	 * @return string|bool Job ID on success, false on failure.
	 */
	public function enqueue_job( $job_type, $payload = array(), $options = array() ) {
		if ( ! $this->queue_manager ) {
			return false;
		}

		// Create job instance based on type.
		$job = $this->create_job_instance( $job_type, $payload );
		if ( ! $job ) {
			return false;
		}

		// Set job options.
		if ( isset( $options[ 'priority' ] ) ) {
			$job->set_priority( (int) $options[ 'priority' ] );
		}

		if ( isset( $options[ 'queue' ] ) ) {
			$job->set_queue_name( $options[ 'queue' ] );
		}

		if ( isset( $options[ 'delay' ] ) ) {
			$job->set_delay_until( time() + (int) $options[ 'delay' ] );
		}

		// Enqueue the job.
		return $this->queue_manager->enqueue( $job );
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
				/**
				 * Filter to allow custom job types.
				 *
				 * @since 1.0.0
				 * @param Queue_Job|null $job     Job instance.
				 * @param string         $job_type Job type.
				 * @param array          $payload  Job payload.
				 */
				return apply_filters( 'redis_queue_demo_create_job', null, $job_type, $payload );
		}
	}
}

/**
 * Get the main plugin instance.
 *
 * @since 1.0.0
 * @return Redis_Queue_Demo
 */
function redis_queue_demo() {
	return Redis_Queue_Demo::get_instance();
}

// Initialize the plugin.
redis_queue_demo();

/**
 * Helper function to enqueue a job.
 *
 * @since 1.0.0
 * @param string $job_type Job type.
 * @param array  $payload  Job payload.
 * @param array  $options  Job options.
 * @return string|bool Job ID on success, false on failure.
 */
function redis_queue_enqueue_job( $job_type, $payload = array(), $options = array() ) {
	return redis_queue_demo()->enqueue_job( $job_type, $payload, $options );
}

/**
 * Helper function to process jobs.
 *
 * @since 1.0.0
 * @param array $queues   Queue names to process.
 * @param int   $max_jobs Maximum jobs to process.
 * @return array Processing results.
 */
function redis_queue_process_jobs( $queues = array( 'default', 'email', 'media', 'api' ), $max_jobs = 10 ) {
	$plugin = redis_queue_demo();
	if ( ! $plugin->queue_manager || ! $plugin->job_processor ) {
		return array(
			'success'    => false,
			'processed'  => 0,
			'successful' => 0,
			'failed'     => 0,
			'errors'     => array( 'Queue system not initialized' ),
		);
	}

	$worker = new Sync_Worker( $plugin->queue_manager, $plugin->job_processor );
	return $worker->process_jobs( $queues, $max_jobs );
}
