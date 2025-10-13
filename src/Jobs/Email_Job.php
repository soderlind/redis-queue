<?php
namespace Soderlind\RedisQueue\Jobs;

use Exception;
use Soderlind\RedisQueue\Contracts\Job_Result;

class Email_Job extends Abstract_Base_Job {
	public function __construct( $payload = [] ) {
		parent::__construct( $payload );
		$this->queue_name = 'email';
		$this->priority   = 20;
		$this->timeout    = 120;
	}
	public function get_job_type() {
		return 'email';
	}
	public function execute() {
		try {
			$type = $this->get_payload_value( 'type', 'single' );
			return match ( $type ) { 'single' => $this->send_single_email(), 'bulk' => $this->send_bulk_emails(), 'newsletter' => $this->send_newsletter(), default => $this->failure( 'Unknown email type: ' . $type ), };
		} catch (Exception $e) {
			return $this->failure( $e->getMessage(), $e->getCode() );
		}
	}
	private function send_single_email() {
		$to      = $this->get_payload_value( 'to' );
		$subject = $this->get_payload_value( 'subject' );
		$message = $this->get_payload_value( 'message' );
		$headers = $this->get_payload_value( 'headers', [] );
		if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
			return $this->failure( 'Missing required email fields: to, subject, message' );
		}
		$attachments = $this->get_payload_value( 'attachments', [] );
		$sent        = \wp_mail( $to, $subject, $message, $headers, $attachments );
		if ( $sent ) {
			return $this->success( [ 'sent' => true, 'to' => $to ], [ 'email_type' => 'single' ] );
		}
		global $phpmailer;
		$phpmailer_error = ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) ? $phpmailer->ErrorInfo : null;
		return $this->failure( 'Failed to send email to: ' . $to, null, [ 'email_type' => 'single', 'phpmailer_error' => $phpmailer_error ] );
	}
	private function send_bulk_emails() {
		$emails = $this->get_payload_value( 'emails', [] );
		if ( empty( $emails ) || ! is_array( $emails ) ) {
			return $this->failure( 'No emails provided or invalid format' );
		}
		$sent     = 0;
		$failed   = 0;
		$failures = [];
		foreach ( $emails as $email ) {
			$to      = $email[ 'to' ] ?? '';
			$subject = $email[ 'subject' ] ?? '';
			$message = $email[ 'message' ] ?? '';
			$headers = $email[ 'headers' ] ?? [];
			if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'Missing required fields' ];
				continue;
			}
			$result = \wp_mail( $to, $subject, $message, $headers );
			if ( $result ) {
				$sent++;
			} else {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'wp_mail returned false' ];
			}
			usleep( 100000 );
		}
		return $this->success( [ 'total' => count( $emails ), 'sent' => $sent, 'failed' => $failed, 'failures' => $failures ], [ 'email_type' => 'bulk' ] );
	}
	private function send_newsletter() {
		$subject        = $this->get_payload_value( 'subject' );
		$message        = $this->get_payload_value( 'message' );
		$subscriber_ids = $this->get_payload_value( 'subscriber_ids', [] );
		$headers        = $this->get_payload_value( 'headers', [] );
		if ( empty( $subject ) || empty( $message ) ) {
			return $this->failure( 'Missing required newsletter fields: subject, message' );
		}
		$subscribers = $this->get_newsletter_subscribers( $subscriber_ids );
		if ( empty( $subscribers ) ) {
			return $this->failure( 'No subscribers found' );
		}
		$sent     = 0;
		$failed   = 0;
		$failures = [];
		foreach ( $subscribers as $subscriber ) {
			$to = is_array( $subscriber ) ? $subscriber[ 'email' ] : $subscriber;
			if ( ! \is_email( $to ) ) {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'Invalid email address' ];
				continue;
			}
			$personalized = $this->personalize_message( $message, $subscriber );
			$result       = \wp_mail( $to, $subject, $personalized, $headers );
			if ( $result ) {
				$sent++;
			} else {
				$failed++;
				$failures[] = [ 'to' => $to, 'reason' => 'wp_mail returned false' ];
			}
			usleep( 200000 );
		}
		return $this->success( [ 'total' => count( $subscribers ), 'sent' => $sent, 'failed' => $failed, 'failures' => $failures, 'subject' => $subject ], [ 'email_type' => 'newsletter' ] );
	}
	private function get_newsletter_subscribers( $subscriber_ids = [] ) {
		if ( ! empty( $subscriber_ids ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );
			return $wpdb->get_results( $wpdb->prepare( "SELECT email, display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)", ...$subscriber_ids ), ARRAY_A );
		}
		$users = \get_users( [ 'fields' => [ 'user_email', 'display_name' ] ] );
		$subs  = [];
		foreach ( $users as $u ) {
			$subs[] = [ 'email' => $u->user_email, 'display_name' => $u->display_name ];
		}
		return $subs;
	}
	private function personalize_message( $message, $subscriber ) {
		$name = is_array( $subscriber ) ? ( $subscriber[ 'display_name' ] ?? $subscriber[ 'email' ] ) : $subscriber;
		$repl = [ '{name}' => $name, '{email}' => is_array( $subscriber ) ? $subscriber[ 'email' ] : $subscriber ];
		return str_replace( array_keys( $repl ), array_values( $repl ), $message );
	}
	public function handle_failure( $exception, $attempt ) {
		parent::handle_failure( $exception, $attempt );
		$email_type = $this->get_payload_value( 'type', 'single' );
		\do_action( 'redis_queue_demo_email_job_failed', $this, $exception, $attempt, $email_type );
	}
	public function should_retry( $exception, $attempt ) {
		if ( $exception instanceof Exception && str_contains( $exception->getMessage(), 'Invalid email' ) ) {
			return false;
		}
		if ( ! ( $exception instanceof Exception ) ) {
			return false;
		}
		$email_type = $this->get_payload_value( 'type', 'single' );
		if ( $email_type === 'bulk' && $attempt >= 2 ) {
			return false;
		}
		return parent::should_retry( $exception, $attempt );
	}
	public static function create_single_email( $to, $subject, $message, $headers = [] ) {
		return new self( [ 'type' => 'single', 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers ] );
	}
	public static function create_bulk_emails( $emails ) {
		return new self( [ 'type' => 'bulk', 'emails' => $emails ] );
	}
	public static function create_newsletter( $subject, $message, $subscriber_ids = [], $headers = [] ) {
		return new self( [ 'type' => 'newsletter', 'subject' => $subject, 'message' => $message, 'subscriber_ids' => $subscriber_ids, 'headers' => $headers ] );
	}
}

// Legacy global class alias removed (backward compatibility dropped).
