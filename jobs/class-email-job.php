<?php
/**
 * Email Job Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email processing job.
 * 
 * Handles sending emails asynchronously to prevent blocking the main request.
 *
 * @since 1.0.0
 */
class Email_Job extends Abstract_Base_Job {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param array $payload Email data.
	 */
	public function __construct( $payload = array() ) {
		parent::__construct( $payload );

		$this->queue_name = 'email';
		$this->priority   = 20; // High priority for emails.
		$this->timeout    = 120; // 2 minutes should be enough for email.
	}

	/**
	 * Get the job type identifier.
	 *
	 * @since 1.0.0
	 * @return string Job type.
	 */
	public function get_job_type() {
		return 'email';
	}

	/**
	 * Execute the email job.
	 *
	 * @since 1.0.0
	 * @return Job_Result The job execution result.
	 */
	public function execute() {
		try {
			$email_type = $this->get_payload_value( 'type', 'single' );

			switch ( $email_type ) {
				case 'single':
					return $this->send_single_email();
				case 'bulk':
					return $this->send_bulk_emails();
				case 'newsletter':
					return $this->send_newsletter();
				default:
					return $this->failure( 'Unknown email type: ' . $email_type );
			}

		} catch (Exception $e) {
			return $this->failure( $e->getMessage(), $e->getCode() );
		}
	}

	/**
	 * Send a single email.
	 *
	 * @since 1.0.0
	 * @return Job_Result
	 */
	private function send_single_email() {
		$to      = $this->get_payload_value( 'to' );
		$subject = $this->get_payload_value( 'subject' );
		$message = $this->get_payload_value( 'message' );
		$headers = $this->get_payload_value( 'headers', array() );

		if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
			return $this->failure( 'Missing required email fields: to, subject, message' );
		}

		// Handle attachments if provided.
		$attachments = $this->get_payload_value( 'attachments', array() );

		$sent = wp_mail( $to, $subject, $message, $headers, $attachments );

		if ( $sent ) {
			return $this->success(
				array( 'sent' => true, 'to' => $to ),
				array( 'email_type' => 'single' )
			);
		} else {
			// Attempt to surface PHPMailer error if available.
			$phpmailer_error = null;
			global $phpmailer;
			if ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) {
				$phpmailer_error = $phpmailer->ErrorInfo;
			}
			$metadata = array(
				'email_type'      => 'single',
				'phpmailer_error' => $phpmailer_error,
			);
			return $this->failure( 'Failed to send email to: ' . $to, null, $metadata );
		}
	}

	/**
	 * Send bulk emails.
	 *
	 * @since 1.0.0
	 * @return Job_Result
	 */
	private function send_bulk_emails() {
		$emails   = $this->get_payload_value( 'emails', array() );
		$sent     = 0;
		$failed   = 0;
		$failures = array();

		if ( empty( $emails ) || ! is_array( $emails ) ) {
			return $this->failure( 'No emails provided or invalid format' );
		}

		foreach ( $emails as $email ) {
			$to      = $email[ 'to' ] ?? '';
			$subject = $email[ 'subject' ] ?? '';
			$message = $email[ 'message' ] ?? '';
			$headers = $email[ 'headers' ] ?? array();

			if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
				$failed++;
				$failures[] = array(
					'to'     => $to,
					'reason' => 'Missing required fields',
				);
				continue;
			}

			$result = wp_mail( $to, $subject, $message, $headers );

			if ( $result ) {
				$sent++;
			} else {
				$failed++;
				$failures[] = array(
					'to'     => $to,
					'reason' => 'wp_mail returned false',
				);
			}

			// Add small delay between emails to prevent overwhelming SMTP server.
			usleep( 100000 ); // 0.1 seconds.
		}

		$total = count( $emails );

		return $this->success(
			array(
				'total'    => $total,
				'sent'     => $sent,
				'failed'   => $failed,
				'failures' => $failures,
			),
			array( 'email_type' => 'bulk' )
		);
	}

	/**
	 * Send newsletter email.
	 *
	 * @since 1.0.0
	 * @return Job_Result
	 */
	private function send_newsletter() {
		$subject        = $this->get_payload_value( 'subject' );
		$message        = $this->get_payload_value( 'message' );
		$subscriber_ids = $this->get_payload_value( 'subscriber_ids', array() );
		$headers        = $this->get_payload_value( 'headers', array() );

		if ( empty( $subject ) || empty( $message ) ) {
			return $this->failure( 'Missing required newsletter fields: subject, message' );
		}

		// Get subscribers from database or use provided IDs.
		$subscribers = $this->get_newsletter_subscribers( $subscriber_ids );

		if ( empty( $subscribers ) ) {
			return $this->failure( 'No subscribers found' );
		}

		$sent     = 0;
		$failed   = 0;
		$failures = array();

		foreach ( $subscribers as $subscriber ) {
			$to = is_array( $subscriber ) ? $subscriber[ 'email' ] : $subscriber;

			if ( ! is_email( $to ) ) {
				$failed++;
				$failures[] = array(
					'to'     => $to,
					'reason' => 'Invalid email address',
				);
				continue;
			}

			// Personalize message if subscriber data is available.
			$personalized_message = $this->personalize_message( $message, $subscriber );

			$result = wp_mail( $to, $subject, $personalized_message, $headers );

			if ( $result ) {
				$sent++;
			} else {
				$failed++;
				$failures[] = array(
					'to'     => $to,
					'reason' => 'wp_mail returned false',
				);
			}

			// Add delay between emails.
			usleep( 200000 ); // 0.2 seconds.
		}

		$total = count( $subscribers );

		return $this->success(
			array(
				'total'    => $total,
				'sent'     => $sent,
				'failed'   => $failed,
				'failures' => $failures,
				'subject'  => $subject,
			),
			array( 'email_type' => 'newsletter' )
		);
	}

	/**
	 * Get newsletter subscribers.
	 *
	 * @since 1.0.0
	 * @param array $subscriber_ids Optional specific subscriber IDs.
	 * @return array Array of subscriber data.
	 */
	private function get_newsletter_subscribers( $subscriber_ids = array() ) {
		// This is a demo implementation.
		// In a real scenario, you'd query your subscriber database.

		if ( ! empty( $subscriber_ids ) ) {
			// Get specific subscribers by ID.
			global $wpdb;
			$ids_placeholder = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );

			$subscribers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT email, display_name FROM {$wpdb->users} WHERE ID IN ($ids_placeholder)",
					...$subscriber_ids
				),
				ARRAY_A
			);
		} else {
			// Get all users as subscribers (demo).
			$users       = get_users( array( 'fields' => array( 'user_email', 'display_name' ) ) );
			$subscribers = array();

			foreach ( $users as $user ) {
				$subscribers[] = array(
					'email'        => $user->user_email,
					'display_name' => $user->display_name,
				);
			}
		}

		return $subscribers;
	}

	/**
	 * Personalize message with subscriber data.
	 *
	 * @since 1.0.0
	 * @param string       $message    Original message.
	 * @param array|string $subscriber Subscriber data.
	 * @return string Personalized message.
	 */
	private function personalize_message( $message, $subscriber ) {
		if ( is_array( $subscriber ) ) {
			$name = $subscriber[ 'display_name' ] ?? $subscriber[ 'email' ];
		} else {
			$name = $subscriber;
		}

		// Replace placeholders.
		$replacements = array(
			'{name}'  => $name,
			'{email}' => is_array( $subscriber ) ? $subscriber[ 'email' ] : $subscriber,
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $message );
	}

	/**
	 * Handle job failure specific to email jobs.
	 *
	 * @since 1.0.0
	 * @param Exception|null $exception The exception that caused the failure (if any).
	 * @param int            $attempt   The current attempt number.
	 * @return void
	 */
	public function handle_failure( $exception, $attempt ) {
		parent::handle_failure( $exception, $attempt );

		// Additional email-specific failure handling.
		$email_type = $this->get_payload_value( 'type', 'single' );

		/**
		 * Fires when an email job fails.
		 *
		 * @since 1.0.0
		 * @param Email_Job $job       Email job instance.
		 * @param Exception $exception Exception that caused the failure.
		 * @param int       $attempt   Attempt number.
		 * @param string    $email_type Email type (single, bulk, newsletter).
		 */
		do_action( 'redis_queue_demo_email_job_failed', $this, $exception, $attempt, $email_type );
	}

	/**
	 * Determine if the email job should be retried.
	 *
	 * @since 1.0.0
	 * @param Exception|null $exception The exception that caused the failure (if any).
	 * @param int            $attempt   The current attempt number.
	 * @return bool Whether to retry the job.
	 */
	public function should_retry( $exception, $attempt ) {
		// Don't retry for invalid email addresses (only if we have an exception message).
		if ( $exception instanceof Exception && strpos( $exception->getMessage(), 'Invalid email' ) !== false ) {
			return false;
		}

		// If there is no exception (logical failure like wp_mail returned false), avoid noisy retries.
		if ( ! ( $exception instanceof Exception ) ) {
			return false;
		}

		// Don't retry bulk emails with too many failures.
		$email_type = $this->get_payload_value( 'type', 'single' );
		if ( 'bulk' === $email_type && $attempt >= 2 ) {
			return false;
		}

		return parent::should_retry( $exception, $attempt );
	}

	/**
	 * Create an email job instance.
	 *
	 * @since 1.0.0
	 * @param string $to      Recipient email.
	 * @param string $subject Email subject.
	 * @param string $message Email message.
	 * @param array  $headers Optional headers.
	 * @return Email_Job
	 */
	public static function create_single_email( $to, $subject, $message, $headers = array() ) {
		return new self( array(
			'type'    => 'single',
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		) );
	}

	/**
	 * Create a bulk email job instance.
	 *
	 * @since 1.0.0
	 * @param array $emails Array of email data.
	 * @return Email_Job
	 */
	public static function create_bulk_emails( $emails ) {
		return new self( array(
			'type'   => 'bulk',
			'emails' => $emails,
		) );
	}

	/**
	 * Create a newsletter job instance.
	 *
	 * @since 1.0.0
	 * @param string $subject        Newsletter subject.
	 * @param string $message        Newsletter message.
	 * @param array  $subscriber_ids Optional specific subscriber IDs.
	 * @param array  $headers        Optional headers.
	 * @return Email_Job
	 */
	public static function create_newsletter( $subject, $message, $subscriber_ids = array(), $headers = array() ) {
		return new self( array(
			'type'           => 'newsletter',
			'subject'        => $subject,
			'message'        => $message,
			'subscriber_ids' => $subscriber_ids,
			'headers'        => $headers,
		) );
	}
}