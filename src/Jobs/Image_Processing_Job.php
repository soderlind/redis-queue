<?php
namespace Soderlind\RedisQueueDemo\Jobs;

use Exception;

class Image_Processing_Job extends Abstract_Base_Job {
	public function __construct( $payload = [] ) {
		parent::__construct( $payload );
		$this->queue_name = 'media';
		$this->priority   = 30;
		$this->timeout    = 600;
	}
	public function get_job_type() {
		return 'image_processing';
	}
	public function execute() {
		try {
			$operation = $this->get_payload_value( 'operation', 'thumbnail' );
			return match ( $operation ) { 'thumbnail' => $this->generate_thumbnails(), 'optimize' => $this->optimize_image(), 'watermark' => $this->add_watermark(), 'bulk_thumbnails' => $this->generate_bulk_thumbnails(), default => $this->failure( 'Unknown image operation: ' . $operation ), };
		} catch (Exception $e) {
			return $this->failure( $e->getMessage(), $e->getCode() );
		}
	}
	private function generate_thumbnails() {
		$attachment_id = $this->get_payload_value( 'attachment_id' );
		$sizes         = $this->get_payload_value( 'sizes', [] );
		if ( ! $attachment_id ) {
			return $this->failure( 'Missing attachment ID' );
		}
		$file_path = \get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $this->failure( 'Image file not found: ' . $attachment_id );
		}
		if ( empty( $sizes ) ) {
			$sizes = array_keys( \wp_get_additional_image_sizes() );
			$sizes = array_merge( $sizes, [ 'thumbnail', 'medium', 'medium_large', 'large' ] );
		}
		$generated = [];
		$failed    = [];
		foreach ( $sizes as $size ) {
			try {
				$resized = \image_make_intermediate_size( $file_path, \get_option( $size . '_size_w' ), \get_option( $size . '_size_h' ), \get_option( $size . '_crop' ) );
				if ( $resized ) {
					$generated[ $size ] = $resized;
				} else {
					$failed[] = $size;
				}
			} catch (Exception $e) {
				$failed[] = [ 'size' => $size, 'reason' => $e->getMessage() ];
			}
		}
		if ( ! empty( $generated ) ) {
			$metadata            = \wp_get_attachment_metadata( $attachment_id );
			$metadata[ 'sizes' ] = array_merge( $metadata[ 'sizes' ] ?? [], $generated );
			\wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		return $this->success( [ 'attachment_id' => $attachment_id, 'generated_sizes' => $generated, 'failed_sizes' => $failed, 'total_sizes' => count( $sizes ), 'successful_sizes' => count( $generated ) ], [ 'operation' => 'thumbnail' ] );
	}
	private function optimize_image() {
		$attachment_id = $this->get_payload_value( 'attachment_id' );
		$quality       = $this->get_payload_value( 'quality', 85 );
		$format        = $this->get_payload_value( 'format', null );
		if ( ! $attachment_id ) {
			return $this->failure( 'Missing attachment ID' );
		}
		$file_path = \get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $this->failure( 'Image file not found: ' . $attachment_id );
		}
		$original = filesize( $file_path );
		$backup   = $file_path . '.backup';
		if ( ! copy( $file_path, $backup ) ) {
			return $this->failure( 'Failed to create backup' );
		}
		try {
			$image_type = \wp_check_filetype( $file_path );
			$image      = \wp_get_image_editor( $file_path );
			if ( \is_wp_error( $image ) ) {
				unlink( $backup );
				return $this->failure( 'Failed to load image: ' . $image->get_error_message() );
			}
			$image->set_quality( $quality );
			if ( $format && $format !== $image_type[ 'ext' ] ) {
				$new_path = preg_replace( '/\.[^.]+$/', '.' . $format, $file_path );
				$saved    = $image->save( $new_path, 'image/' . $format );
				if ( \is_wp_error( $saved ) ) {
					unlink( $backup );
					return $this->failure( 'Failed to convert image format: ' . $saved->get_error_message() );
				}
				\update_attached_file( $attachment_id, $saved[ 'path' ] );
				$file_path = $saved[ 'path' ];
			} else {
				$saved = $image->save( $file_path );
				if ( \is_wp_error( $saved ) ) {
					copy( $backup, $file_path );
					unlink( $backup );
					return $this->failure( 'Failed to optimize image: ' . $saved->get_error_message() );
				}
			}
			$new_size      = filesize( $file_path );
			$saved_bytes   = $original - $new_size;
			$saved_percent = $original > 0 ? ( $saved_bytes / $original ) * 100 : 0;
			unlink( $backup );
			return $this->success( [ 'attachment_id' => $attachment_id, 'original_size' => $original, 'new_size' => $new_size, 'saved_bytes' => $saved_bytes, 'saved_percent' => round( $saved_percent, 2 ), 'quality' => $quality, 'format_changed' => (bool) $format ], [ 'operation' => 'optimize' ] );
		} catch (Exception $e) {
			if ( file_exists( $backup ) ) {
				copy( $backup, $file_path );
				unlink( $backup );
			}
			return $this->failure( 'Image optimization failed: ' . $e->getMessage() );
		}
	}
	private function add_watermark() {
		$attachment_id = $this->get_payload_value( 'attachment_id' );
		$watermark_id  = $this->get_payload_value( 'watermark_id' );
		$position      = $this->get_payload_value( 'position', 'bottom-right' );
		$opacity       = $this->get_payload_value( 'opacity', 50 );
		$margin        = $this->get_payload_value( 'margin', 10 );
		if ( ! $attachment_id || ! $watermark_id ) {
			return $this->failure( 'Missing attachment ID or watermark ID' );
		}
		$image_path     = \get_attached_file( $attachment_id );
		$watermark_path = \get_attached_file( $watermark_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return $this->failure( 'Main image file not found' );
		}
		if ( ! $watermark_path || ! file_exists( $watermark_path ) ) {
			return $this->failure( 'Watermark image file not found' );
		}
		try {
			$image     = \wp_get_image_editor( $image_path );
			$watermark = \wp_get_image_editor( $watermark_path );
			if ( \is_wp_error( $image ) ) {
				return $this->failure( 'Failed to load main image: ' . $image->get_error_message() );
			}
			if ( \is_wp_error( $watermark ) ) {
				return $this->failure( 'Failed to load watermark: ' . $watermark->get_error_message() );
			}
			$image_size     = $image->get_size();
			$watermark_size = $watermark->get_size();
			$coords         = $this->calculate_watermark_position( $image_size, $watermark_size, $position, $margin );
			$result         = $image->save( $image_path );
			if ( \is_wp_error( $result ) ) {
				return $this->failure( 'Failed to save watermarked image: ' . $result->get_error_message() );
			}
			return $this->success( [ 'attachment_id' => $attachment_id, 'watermark_id' => $watermark_id, 'position' => $position, 'coordinates' => $coords, 'opacity' => $opacity ], [ 'operation' => 'watermark' ] );
		} catch (Exception $e) {
			return $this->failure( 'Watermark application failed: ' . $e->getMessage() );
		}
	}
	private function generate_bulk_thumbnails() {
		$ids   = $this->get_payload_value( 'attachment_ids', [] );
		$sizes = $this->get_payload_value( 'sizes', [] );
		if ( empty( $ids ) ) {
			return $this->failure( 'No attachment IDs provided' );
		}
		$processed = 0;
		$failed    = 0;
		$results   = [];
		foreach ( $ids as $id ) {
			try {
				$job = new self( [ 'operation' => 'thumbnail', 'attachment_id' => $id, 'sizes' => $sizes ] );
				$res = $job->generate_thumbnails();
				if ( $res->is_successful() ) {
					$processed++;
					$results[ $id ] = $res->get_data();
				} else {
					$failed++;
					$results[ $id ] = [ 'error' => $res->get_error_message() ];
				}
			} catch (Exception $e) {
				$failed++;
				$results[ $id ] = [ 'error' => $e->getMessage() ];
			}
		}
		return $this->success( [ 'total' => count( $ids ), 'processed' => $processed, 'failed' => $failed, 'results' => $results ], [ 'operation' => 'bulk_thumbnails' ] );
	}
	private function calculate_watermark_position( $image_size, $watermark_size, $position, $margin ) {
		$x = 0;
		$y = 0;
		switch ( $position ) {
			case 'top-left':
				$x = $margin;
				$y = $margin;
				break;
			case 'top-center':
				$x = ( $image_size[ 'width' ] - $watermark_size[ 'width' ] ) / 2;
				$y = $margin;
				break;
			case 'top-right':
				$x = $image_size[ 'width' ] - $watermark_size[ 'width' ] - $margin;
				$y = $margin;
				break;
			case 'center-left':
				$x = $margin;
				$y = ( $image_size[ 'height' ] - $watermark_size[ 'height' ] ) / 2;
				break;
			case 'center':
				$x = ( $image_size[ 'width' ] - $watermark_size[ 'width' ] ) / 2;
				$y = ( $image_size[ 'height' ] - $watermark_size[ 'height' ] ) / 2;
				break;
			case 'center-right':
				$x = $image_size[ 'width' ] - $watermark_size[ 'width' ] - $margin;
				$y = ( $image_size[ 'height' ] - $watermark_size[ 'height' ] ) / 2;
				break;
			case 'bottom-left':
				$x = $margin;
				$y = $image_size[ 'height' ] - $watermark_size[ 'height' ] - $margin;
				break;
			case 'bottom-center':
				$x = ( $image_size[ 'width' ] - $watermark_size[ 'width' ] ) / 2;
				$y = $image_size[ 'height' ] - $watermark_size[ 'height' ] - $margin;
				break;
			case 'bottom-right':
			default:
				$x = $image_size[ 'width' ] - $watermark_size[ 'width' ] - $margin;
				$y = $image_size[ 'height' ] - $watermark_size[ 'height' ] - $margin;
		}
		return [ 'x' => max( 0, $x ), 'y' => max( 0, $y ) ];
	}
	public static function create_thumbnail_job( $attachment_id, $sizes = [] ) {
		return new self( [ 'operation' => 'thumbnail', 'attachment_id' => $attachment_id, 'sizes' => $sizes ] );
	}
	public static function create_optimization_job( $attachment_id, $quality = 85, $format = null ) {
		return new self( [ 'operation' => 'optimize', 'attachment_id' => $attachment_id, 'quality' => $quality, 'format' => $format ] );
	}
	public static function create_watermark_job( $attachment_id, $watermark_id, $position = 'bottom-right', $opacity = 50, $margin = 10 ) {
		return new self( [ 'operation' => 'watermark', 'attachment_id' => $attachment_id, 'watermark_id' => $watermark_id, 'position' => $position, 'opacity' => $opacity, 'margin' => $margin ] );
	}
}

// Legacy global class alias removed (backward compatibility dropped).
