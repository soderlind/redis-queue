<?php
namespace Soderlind\RedisQueue\Jobs;

use Exception;
use Soderlind\RedisQueue\Contracts\Job_Result;

/**
 * Email Job.
 * Handles sending emails through WordPress wp_mail function.
 * Supports single emails, bulk emails, and newsletters.
 */
class Email_Job extends Abstract_Base_Job {
	/**
	 * Constructor.
	 * Sets email-specific defaults for queue, priority, and timeout.
	 * 
	 * @param array $payload Job payload data.
	 */
	public function __construct( $payload = [] ) {
		parent::__construct( $payload );
		$this->queue_name = 'email';
		$this->priority   = 20; // Higher priority than default jobs
		$this->timeout    = 120; // 2 minutes for email sending
	}

	/**
	 * Get job type identifier.
	 * 
	 * @return string Job type 'email'.
	 */
	public function get_job_type() {
		return 'email';
	}

	/**
	 * Execute the email job.
	 * Routes to appropriate handler based on email type.
	 * 
	 * @return Job_Result Job execution result.
	 */
	public function execute() {
		try {
			$type = $this->get_payload_value( 'type', 'single' );
			
			// Route to appropriate email handler.
			return match ( $type ) {
				'single'     => $this->send_single_email(),
				'bulk'       => $this->send_bulk_emails(),
				'newsletter' => $this->send_newsletter(),
				default      => $this->failure( 'Unknown email type: ' . $type ),
			};
		} catch (Exception $e) {
			return $this->failure( $e->getMessage(), $e->getCode() );
		}
	}

	/**
	 * Send a single email.
	 * 
	 * @return Job_Result Result with send status.
	 */
	private function send_single_email() {
		// Get email parameters from payload.
		$to      = $this->get_payload_value( 'to' );
		$subject = $this->get_payload_value( 'subject' );
		$message = $this->get_payload_value( 'message' );
		$headers = $this->get_payload_value( 'headers', [] );
		
		// Validate required fields.
		if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
			return $this->failure( 'Missing required email fields: to, subject, message' );
		}
		
		// Send email via wp_mail.
		$attachments = $this->get_payload_value( 'attachments', [] );
		$sent        = \wp_mail( $to, $subject, $message, $headers, $attachments );
		
		if ( $sent ) {
			return $this->success( [ 'sent' => true, 'to' => $to ], [ 'email_type' => 'single' ] );
		}
		
		// Get PHPMailer error if available.
		global $phpmailer;
		$phpmailer_error = ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) ? $phpmailer->ErrorInfo : null;
		
		return $this->failure( 'Failed to send email to: ' . $to, null, [ 'email_type' => 'single', 'phpmailer_error' => $phpmailer_error ] );
	}

	/**
	 * Send multiple emails.
	 * Each email can have different recipients, subjects, and messages.
	 * 
	 * @return Job_Result Result with batch send statistics.
	 */
	private function send_bulk_emails() {
		$emails = $this->get_payload_value( 'emails', [] );
		
		if ( empty( $emails ) || ! is_array( $emails ) ) {
			return $this->failure( 'No emails provided or invalid format' );
		}
		
		$sent     = 0;
		$failed   = 0;
		$failures = [];
		
		// Process each email.
		foreach ( $emails as $email ) {
			$to      = $email[ 'to' ] ?? '';
			$subject = $email[ 'subject' ] ?? '';
			$message = $email[ 'message' ] ?? '';
			$headers = $email[ 'headers' ] ?? [];
			
			// Validate required fields.
			if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'Missing required fields' ];
				continue;
			}
			
			// Send email.
			$result = \wp_mail( $to, $subject, $message, $headers );
			
			if ( $result ) {
				$sent++;
			} else {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'wp_mail returned false' ];
			}
			
			// Small delay to avoid overwhelming mail server.
			usleep( 100000 ); // 0.1 seconds
		}
		
		return $this->success(
			[
				'total'    => count( $emails ),
				'sent'     => $sent,
				'failed'   => $failed,
				'failures' => $failures
			],
			[ 'email_type' => 'bulk' ]
		);
	}

	/**
	 * Send newsletter to subscribers.
	 * Sends same content to multiple recipients with personalization.
	 * 
	 * @return Job_Result Result with newsletter send statistics.
	 */
	private function send_newsletter() {
		$subject        = $this->get_payload_value( 'subject' );
		$message        = $this->get_payload_value( 'message' );
		$subscriber_ids = $this->get_payload_value( 'subscriber_ids', [] );
		$headers        = $this->get_payload_value( 'headers', [] );
		
		// Validate required fields.
		if ( empty( $subject ) || empty( $message ) ) {
			return $this->failure( 'Missing required newsletter fields: subject, message' );
		}
		
		// Get subscriber list.
		$subscribers = $this->get_newsletter_subscribers( $subscriber_ids );
		if ( empty( $subscribers ) ) {
			return $this->failure( 'No subscribers found' );
		}
		
		$sent     = 0;
		$failed   = 0;
		$failures = [];
		
		// Send to each subscriber.
		foreach ( $subscribers as $subscriber ) {
			$to = is_array( $subscriber ) ? $subscriber[ 'email' ] : $subscriber;
			
			// Validate email address.
			if ( ! \is_email( $to ) ) {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'Invalid email address' ];
				continue;
			}
			
			// Personalize message for subscriber.
			$personalized = $this->personalize_message( $message, $subscriber );
			$result       = \wp_mail( $to, $subject, $personalized, $headers );
			
			if ( $result ) {
				$sent++;
			} else {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'wp_mail returned false' ];
			}
			
			// Delay to avoid overwhelming mail server.
			usleep( 200000 ); // 0.2 seconds
		}
		
		return $this->success(
			[
				'total'    => count( $subscribers ),
				'sent'     => $sent,
				'failed'   => $failed,
				'failures' => $failures,
				'subject'  => $subject
			],
			[ 'email_type' => 'newsletter' ]
		);
	}

	/**
	 * Get newsletter subscribers.
	 * 
	 * @param array $subscriber_ids Optional specific subscriber IDs.
	 * @return array Array of subscriber data.
	 */
	private function get_newsletter_subscribers( $subscriber_ids = [] ) {
		// Get specific subscribers if IDs provided.
		if ( ! empty( $subscriber_ids ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );
			return $wpdb->get_results( $wpdb->prepare( "SELECT email, display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)", ...$subscriber_ids ), ARRAY_A );
		}
		
		// Get all users as subscribers.
		$users = \get_users( [ 'fields' => [ 'user_email', 'display_name' ] ] );
		$subs  = [];
		foreach ( $users as $u ) {
			$subs[] = [ 'email' => $u->user_email, 'display_name' => $u->display_name ];
		}
		return $subs;
	}

	/**
	 * Personalize message with subscriber data.
	 * Replaces {name} and {email} placeholders.
	 * 
	 * @param string       $message    Message template.
	 * @param array|string $subscriber Subscriber data.
	 * @return string Personalized message.
	 */
	private function personalize_message( $message, $subscriber ) {
		$name = is_array( $subscriber ) ? ( $subscriber[ 'display_name' ] ?? $subscriber[ 'email' ] ) : $subscriber;
		$repl = [
			'{name}'  => $name,
			'{email}' => is_array( $subscriber ) ? $subscriber[ 'email' ] : $subscriber
		];
		return str_replace( array_keys( $repl ), array_values( $repl ), $message );
	}

	/**
	 * Handle email job failure.
	 * Extends parent handler with email-specific action.
	 * 
	 * @param mixed $exception Exception or failure reason.
	 * @param int   $attempt   Current attempt number.
	 */
	public function handle_failure( $exception, $attempt ) {
		parent::handle_failure( $exception, $attempt );
		$email_type = $this->get_payload_value( 'type', 'single' );
		\do_action( 'redis_queue_email_job_failed', $this, $exception, $attempt, $email_type );
	}

	/**
	 * Determine if email job should be retried.
	 * Don't retry invalid emails or bulk emails after 2 attempts.
	 * 
	 * @param mixed $exception Exception or failure reason.
	 * @param int   $attempt   Current attempt number.
	 * @return bool True if job should be retried.
	 */
	public function should_retry( $exception, $attempt ) {
		// Don't retry invalid email addresses.
		if ( $exception instanceof Exception && str_contains( $exception->getMessage(), 'Invalid email' ) ) {
			return false;
		}
		
		// Don't retry non-exception failures.
		if ( ! ( $exception instanceof Exception ) ) {
			return false;
		}
		
		// Limit bulk email retries.
		$email_type = $this->get_payload_value( 'type', 'single' );
		if ( $email_type === 'bulk' && $attempt >= 2 ) {
			return false;
		}
		
		return parent::should_retry( $exception, $attempt );
	}

	/**
	 * Create single email job.
	 * Static factory method for creating single email jobs.
	 * 
	 * @param string $to      Recipient email address.
	 * @param string $subject Email subject.
	 * @param string $message Email message.
	 * @param array  $headers Optional email headers.
	 * @return self Email job instance.
	 */
	public static function create_single_email( $to, $subject, $message, $headers = [] ) {
		return new self( [ 'type' => 'single', 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers ] );
	}

	/**
	 * Create bulk email job.
	 * Static factory method for creating bulk email jobs.
	 * 
	 * @param array $emails Array of email data (to, subject, message, headers).
	 * @return self Email job instance.
	 */
	public static function create_bulk_emails( $emails ) {
		return new self( [ 'type' => 'bulk', 'emails' => $emails ] );
	}

	/**
	 * Create newsletter job.
	 * Static factory method for creating newsletter jobs.
	 * 
	 * @param string $subject        Newsletter subject.
	 * @param string $message        Newsletter message (supports {name} and {email} placeholders).
	 * @param array  $subscriber_ids Optional specific subscriber IDs.
	 * @param array  $headers        Optional email headers.
	 * @return self Email job instance.
	 */
	public static function create_newsletter( $subject, $message, $subscriber_ids = [], $headers = [] ) {
		return new self( [ 'type' => 'newsletter', 'subject' => $subject, 'message' => $message, 'subscriber_ids' => $subscriber_ids, 'headers' => $headers ] );
	}
}

// Legacy global class alias removed (backward compatibility dropped).
