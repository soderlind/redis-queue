<?php
namespace Soderlind\RedisQueueDemo\Core;

use Soderlind\RedisQueueDemo\Update\GitHub_Plugin_Updater;

/**
 * Main Redis Queue Demo class (namespaced).
 */
final class Redis_Queue_Demo {
	private static $instance = null;

	/** @var \Redis_Queue_Manager|null */
	public $queue_manager = null; // Legacy classes not yet namespaced.
	/** @var \Job_Processor|null */
	public $job_processor = null;
	/** @var \REST_Controller|null */
	public $rest_controller = null;
	/** @var \Admin_Interface|null */
	public $admin_interface = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Updater (use namespaced updater class).
		GitHub_Plugin_Updater::create_with_assets(
			'https://github.com/soderlind/redis-queue-demo',
			REDIS_QUEUE_DEMO_PLUGIN_FILE,
			'redis-queue-demo',
			'/redis-queue-demo\.zip/',
			'main'
		);
		$this->init_hooks();
	}

	private function init_hooks(): void {
		\register_activation_hook( REDIS_QUEUE_DEMO_PLUGIN_FILE, [ $this, 'activate' ] );
		\register_deactivation_hook( REDIS_QUEUE_DEMO_PLUGIN_FILE, [ $this, 'deactivate' ] );
		\add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		\add_action( 'init', [ $this, 'init' ] );
		\add_action( 'rest_api_init', [ $this, 'init_rest_api' ] );
	}

	public function init(): void {
		$this->load_dependencies();
		$this->init_components();
		\do_action( 'redis_queue_demo_init', $this );
	}

	private function load_dependencies(): void {
		// All core classes now autoloaded via Composer. Legacy requires removed.
	}

	private function init_components(): void {
		$this->queue_manager   = new Redis_Queue_Manager();
		$this->job_processor   = new Job_Processor( $this->queue_manager );
		$this->rest_controller = new \Soderlind\RedisQueueDemo\API\REST_Controller( $this->queue_manager, $this->job_processor );
		if ( \is_admin() ) {
			// Use namespaced Admin_Interface (legacy admin/class-admin-interface.php retained temporarily for reference / UI parity).
			$this->admin_interface = new \Soderlind\RedisQueueDemo\Admin\Admin_Interface( $this->queue_manager, $this->job_processor );
			if ( method_exists( $this->admin_interface, 'init' ) ) {
				$this->admin_interface->init();
			}
		}
	}

	public function init_rest_api(): void {
		if ( $this->rest_controller ) {
			$this->rest_controller->register_routes();
		}
	}

	public function load_textdomain(): void {
		\load_plugin_textdomain( 'redis-queue-demo', false, dirname( REDIS_QUEUE_DEMO_PLUGIN_BASENAME ) . '/languages' );
	}

	public function activate(): void {
		if ( \version_compare( PHP_VERSION, '8.3', '<' ) ) {
			\deactivate_plugins( REDIS_QUEUE_DEMO_PLUGIN_BASENAME );
			\wp_die( \esc_html__( 'Redis Queue Demo requires PHP 8.3 or higher.', 'redis-queue-demo' ), \esc_html__( 'Plugin Activation Error', 'redis-queue-demo' ), [ 'back_link' => true ] );
		}
		if ( ! \extension_loaded( 'redis' ) && ! \class_exists( 'Predis\\Client' ) ) {
			\deactivate_plugins( REDIS_QUEUE_DEMO_PLUGIN_BASENAME );
			\wp_die( \esc_html__( 'Redis Queue Demo requires either the Redis PHP extension or Predis library.', 'redis-queue-demo' ), \esc_html__( 'Plugin Activation Error', 'redis-queue-demo' ), [ 'back_link' => true ] );
		}
		$this->create_tables();
		$this->set_default_options();
		\flush_rewrite_rules();
		\do_action( 'redis_queue_demo_activate' );
	}

	public function deactivate(): void {
		\wp_clear_scheduled_hook( 'redis_queue_demo_process_jobs' );
		\flush_rewrite_rules();
		\do_action( 'redis_queue_demo_deactivate' );
	}

	private function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'redis_queue_jobs';
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
		foreach ( $default_options as $option => $value ) {
			$option_name = 'redis_queue_demo_' . $option;
			if ( false === \get_option( $option_name ) ) {
				\add_option( $option_name, $value );
			}
		}
	}

	public function get_option( $option, $default = null ) {
		return \get_option( 'redis_queue_demo_' . $option, $default );
	}
	public function update_option( $option, $value ) {
		return \update_option( 'redis_queue_demo_' . $option, $value );
	}
	public function get_queue_manager() {
		return $this->queue_manager;
	}
	public function get_job_processor() {
		return $this->job_processor;
	}

	public function enqueue_job( $job_type, $payload = [], $options = [] ) {
		if ( ! $this->queue_manager ) {
			return false;
		}
		$job = $this->create_job_instance( $job_type, $payload );
		if ( ! $job ) {
			return false;
		}
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

	private function create_job_instance( $job_type, $payload ) {
		switch ( $job_type ) {
			case 'email':
				return new \Soderlind\RedisQueueDemo\Jobs\Email_Job( $payload );
			case 'image_processing':
				return new \Soderlind\RedisQueueDemo\Jobs\Image_Processing_Job( $payload );
			case 'api_sync':
				return new \Soderlind\RedisQueueDemo\Jobs\API_Sync_Job( $payload );
			default:
				return \apply_filters( 'redis_queue_demo_create_job', null, $job_type, $payload );
		}
	}
}
