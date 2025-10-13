<?php
namespace Soderlind\RedisQueue\Jobs;

use Exception;

class API_Sync_Job extends Abstract_Base_Job {
	public function __construct( $payload = [] ) {
		parent::__construct( $payload );
		$this->queue_name = 'api';
		$this->priority   = 40;
		$this->timeout    = 300;
	}
	public function get_job_type() {
		return 'api_sync';
	}
	public function execute() {
		try {
			$type = $this->get_payload_value( 'type', 'generic' );
			return match ( $type ) { 'social_media' => $this->sync_social_media(), 'crm' => $this->sync_crm_data(), 'analytics' => $this->sync_analytics(), 'webhook' => $this->send_webhook(), default => $this->generic_api_call(), };
		} catch (Exception $e) {
			return $this->failure( $e->getMessage(), $e->getCode() );
		}
	}
	private function sync_social_media() {
		$platforms = $this->get_payload_value( 'platforms', [] );
		$post      = $this->get_payload_value( 'post_data', [] );
		if ( empty( $platforms ) || empty( $post ) ) {
			return $this->failure( 'Missing platforms or post data' );
		}
		$results    = [];
		$successful = 0;
		$failed     = 0;
		foreach ( $platforms as $platform => $config ) {
			try {
				$result               = $this->post_to_social_platform( $platform, $post, $config );
				$results[ $platform ] = $result;
				if ( $result[ 'success' ] ) {
					$successful++;
				} else {
					$failed++;
				}
			} catch (Exception $e) {
				$failed++;
				$results[ $platform ] = [ 'success' => false, 'error' => $e->getMessage() ];
			}
		}
		return $this->success( [ 'total' => count( $platforms ), 'successful' => $successful, 'failed' => $failed, 'results' => $results, 'post_data' => $post ], [ 'sync_type' => 'social_media' ] );
	}
	private function sync_crm_data() {
		$crm  = $this->get_payload_value( 'crm_system' );
		$op   = $this->get_payload_value( 'operation', 'sync' );
		$data = $this->get_payload_value( 'data', [] );
		if ( ! $crm ) {
			return $this->failure( 'CRM system not specified' );
		}
		return match ( $op ) { 'create_contact' => $this->create_crm_contact( $crm, $data ), 'update_contact' => $this->update_crm_contact( $crm, $data ), 'sync_contacts' => $this->sync_crm_contacts( $crm, $data ), default => $this->failure( 'Unknown CRM operation: ' . $op ), };
	}
	private function sync_analytics() {
		$provider = $this->get_payload_value( 'provider', 'google_analytics' );
		$metrics  = $this->get_payload_value( 'metrics', [] );
		$range    = $this->get_payload_value( 'date_range', [] );
		return match ( $provider ) { 'google_analytics' => $this->sync_google_analytics( $metrics, $range ), 'custom_tracking' => $this->sync_custom_tracking( $metrics, $range ), default => $this->failure( 'Unknown analytics provider: ' . $provider ), };
	}
	private function send_webhook() {
		$url     = $this->get_payload_value( 'url' );
		$method  = $this->get_payload_value( 'method', 'POST' );
		$headers = $this->get_payload_value( 'headers', [] );
		$data    = $this->get_payload_value( 'data', [] );
		if ( ! $url ) {
			return $this->failure( 'Webhook URL not provided' );
		}
		$args = [ 'method' => $method, 'headers' => $headers, 'timeout' => 30 ];
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args[ 'body' ] = \wp_json_encode( $data );
			if ( ! isset( $headers[ 'Content-Type' ] ) ) {
				$args[ 'headers' ][ 'Content-Type' ] = 'application/json';
			}
		}
		$response = \wp_remote_request( $url, $args );
		if ( \is_wp_error( $response ) ) {
			return $this->failure( 'Webhook request failed: ' . $response->get_error_message() );
		}
		$code = \wp_remote_retrieve_response_code( $response );
		$body = \wp_remote_retrieve_body( $response );
		if ( $code >= 200 && $code < 300 ) {
			return $this->success( [ 'url' => $url, 'method' => $method, 'response_code' => $code, 'response_body' => $body ], [ 'sync_type' => 'webhook' ] );
		}
		return $this->failure( sprintf( 'Webhook returned error code %d: %s', $code, $body ), $code );
	}
	private function generic_api_call() {
		$url     = $this->get_payload_value( 'url' );
		$method  = $this->get_payload_value( 'method', 'GET' );
		$headers = $this->get_payload_value( 'headers', [] );
		$data    = $this->get_payload_value( 'data', [] );
		if ( ! $url ) {
			return $this->failure( 'API URL not provided' );
		}
		$args = [ 'method' => $method, 'headers' => $headers, 'timeout' => 60 ];
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) && ! empty( $data ) ) {
			$args[ 'body' ] = \wp_json_encode( $data );
			if ( ! isset( $headers[ 'Content-Type' ] ) ) {
				$args[ 'headers' ][ 'Content-Type' ] = 'application/json';
			}
		}
		$response = \wp_remote_request( $url, $args );
		if ( \is_wp_error( $response ) ) {
			return $this->failure( 'API request failed: ' . $response->get_error_message() );
		}
		$code = \wp_remote_retrieve_response_code( $response );
		$body = \wp_remote_retrieve_body( $response );
		return $this->success( [ 'url' => $url, 'method' => $method, 'response_code' => $code, 'response_body' => $body, 'request_data' => $data ], [ 'sync_type' => 'generic' ] );
	}
	private function post_to_social_platform( $platform, $post, $config ) {
		$endpoints = [ 'facebook' => 'https://graph.facebook.com/v12.0/me/feed', 'twitter' => 'https://api.twitter.com/2/tweets', 'linkedin' => 'https://api.linkedin.com/v2/shares' ];
		if ( ! isset( $endpoints[ $platform ] ) ) {
			throw new Exception( 'Unsupported platform: ' . $platform );
		}
		$url     = $endpoints[ $platform ];
		$headers = [];
		if ( isset( $config[ 'access_token' ] ) ) {
			$headers[ 'Authorization' ] = 'Bearer ' . $config[ 'access_token' ];
		}
		$args     = [ 'method' => 'POST', 'headers' => $headers, 'body' => \wp_json_encode( $post ), 'timeout' => 30 ];
		$response = \wp_remote_post( $url, $args );
		if ( \is_wp_error( $response ) ) {
			throw new Exception( 'Platform API error: ' . $response->get_error_message() );
		}
		$code = \wp_remote_retrieve_response_code( $response );
		$body = \wp_remote_retrieve_body( $response );
		return [ 'success' => $code >= 200 && $code < 300, 'response_code' => $code, 'response_body' => $body, 'platform' => $platform ];
	}
	private function create_crm_contact( $crm, $data ) {
		$endpoints = [ 'salesforce' => 'https://example.salesforce.com/services/data/v52.0/sobjects/Contact/', 'hubspot' => 'https://api.hubapi.com/crm/v3/objects/contacts' ];
		if ( ! isset( $endpoints[ $crm ] ) ) {
			return $this->failure( 'Unsupported CRM system: ' . $crm );
		}
		$contact_id = 'contact_' . uniqid();
		return $this->success( [ 'crm_system' => $crm, 'operation' => 'create_contact', 'contact_id' => $contact_id, 'contact_data' => $data ], [ 'sync_type' => 'crm' ] );
	}
	private function update_crm_contact( $crm, $data ) {
		$contact_id = $data[ 'contact_id' ] ?? null;
		if ( ! $contact_id ) {
			return $this->failure( 'Contact ID required for update' );
		}
		return $this->success( [ 'crm_system' => $crm, 'operation' => 'update_contact', 'contact_id' => $contact_id, 'updated_data' => $data ], [ 'sync_type' => 'crm' ] );
	}
	private function sync_crm_contacts( $crm, $data ) {
		$batch_size = $data[ 'batch_size' ] ?? 100;
		$offset     = $data[ 'offset' ] ?? 0;
		$synced     = \wp_rand( 50, $batch_size );
		return $this->success( [ 'crm_system' => $crm, 'operation' => 'sync_contacts', 'synced' => $synced, 'offset' => $offset, 'batch_size' => $batch_size ], [ 'sync_type' => 'crm' ] );
	}
	private function sync_google_analytics( $metrics, $range ) {
		$data = [];
		foreach ( $metrics as $metric ) {
			$data[ $metric ] = \wp_rand( 100, 10000 );
		}
		return $this->success( [ 'provider' => 'google_analytics', 'metrics' => $metrics, 'date_range' => $range, 'data' => $data ], [ 'sync_type' => 'analytics' ] );
	}
	private function sync_custom_tracking( $metrics, $range ) {
		$start = $range[ 'start' ] ?? date( 'Y-m-d', strtotime( '-7 days' ) );
		$end   = $range[ 'end' ] ?? date( 'Y-m-d' );
		$data  = [ 'page_views' => \wp_rand( 1000, 5000 ), 'unique_visitors' => \wp_rand( 500, 2000 ), 'bounce_rate' => \wp_rand( 30, 70 ) ];
		return $this->success( [ 'provider' => 'custom_tracking', 'metrics' => $metrics, 'date_range' => [ 'start' => $start, 'end' => $end ], 'data' => $data ], [ 'sync_type' => 'analytics' ] );
	}
	public static function create_social_media_job( $platforms, $post ) {
		return new self( [ 'type' => 'social_media', 'platforms' => $platforms, 'post_data' => $post ] );
	}
	public static function create_webhook_job( $url, $data, $method = 'POST', $headers = [] ) {
		return new self( [ 'type' => 'webhook', 'url' => $url, 'data' => $data, 'method' => $method, 'headers' => $headers ] );
	}
	public static function create_crm_job( $crm, $operation, $data ) {
		return new self( [ 'type' => 'crm', 'crm_system' => $crm, 'operation' => $operation, 'data' => $data ] );
	}
}

// Legacy global class alias removed (backward compatibility dropped).
