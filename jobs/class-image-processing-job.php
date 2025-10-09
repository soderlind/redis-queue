<?php
/**
 * Image Processing Job Class
 *
 * @package RedisQueueDemo
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image processing job.
 * 
 * Handles image operations like thumbnail generation, optimization, and watermarking.
 *
 * @since 1.0.0
 */
class Image_Processing_Job extends Abstract_Base_Job {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param array $payload Image processing data.
	 */
	public function __construct( $payload = array() ) {
		parent::__construct( $payload );

		$this->queue_name = 'media';
		$this->priority   = 30; // Medium priority.
		$this->timeout    = 600; // 10 minutes for image processing.
	}

	/**
	 * Get the job type identifier.
	 *
	 * @since 1.0.0
	 * @return string Job type.
	 */
	public function get_job_type() {
		return 'image_processing';
	}

	/**
	 * Execute the image processing job.
	 *
	 * @since 1.0.0
	 * @return Job_Result The job execution result.
	 */
	public function execute() {
		try {
			$operation = $this->get_payload_value( 'operation', 'thumbnail' );

			switch ( $operation ) {
				case 'thumbnail':
					return $this->generate_thumbnails();
				case 'optimize':
					return $this->optimize_image();
				case 'watermark':
					return $this->add_watermark();
				case 'bulk_thumbnails':
					return $this->generate_bulk_thumbnails();
				default:
					return $this->failure( 'Unknown image operation: ' . $operation );
			}

		} catch (Exception $e) {
			return $this->failure( $e->getMessage(), $e->getCode() );
		}
	}

	/**
	 * Generate thumbnails for an image.
	 *
	 * @since 1.0.0
	 * @return Job_Result
	 */
	private function generate_thumbnails() {
		$attachment_id = $this->get_payload_value( 'attachment_id' );
		$sizes         = $this->get_payload_value( 'sizes', array() );

		if ( ! $attachment_id ) {
			return $this->failure( 'Missing attachment ID' );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $this->failure( 'Image file not found: ' . $attachment_id );
		}

		$generated_sizes = array();
		$failed_sizes    = array();

		// Get all image sizes if none specified.
		if ( empty( $sizes ) ) {
			$sizes = array_keys( wp_get_additional_image_sizes() );
			$sizes = array_merge( $sizes, array( 'thumbnail', 'medium', 'medium_large', 'large' ) );
		}

		foreach ( $sizes as $size ) {
			try {
				$resized = image_make_intermediate_size( $file_path, get_option( $size . '_size_w' ), get_option( $size . '_size_h' ), get_option( $size . '_crop' ) );

				if ( $resized ) {
					$generated_sizes[ $size ] = $resized;
				} else {
					$failed_sizes[] = $size;
				}
			} catch (Exception $e) {
				$failed_sizes[] = array(
					'size'   => $size,
					'reason' => $e->getMessage(),
				);
			}
		}

		// Update attachment metadata.
		if ( ! empty( $generated_sizes ) ) {
			$metadata          = wp_get_attachment_metadata( $attachment_id );
			$metadata[ 'sizes' ] = array_merge( $metadata[ 'sizes' ] ?? array(), $generated_sizes );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return $this->success(
			array(
				'attachment_id'    => $attachment_id,
				'generated_sizes'  => $generated_sizes,
				'failed_sizes'     => $failed_sizes,
				'total_sizes'      => count( $sizes ),
				'successful_sizes' => count( $generated_sizes ),
			),
			array( 'operation' => 'thumbnail' )
		);
	}

	/**
	 * Optimize an image.
	 *
	 * @since 1.0.0
	 * @return Job_Result
	 */
	private function optimize_image() {
		$attachment_id = $this->get_payload_value( 'attachment_id' );
		$quality       = $this->get_payload_value( 'quality', 85 );
		$format        = $this->get_payload_value( 'format', null );

		if ( ! $attachment_id ) {
			return $this->failure( 'Missing attachment ID' );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $this->failure( 'Image file not found: ' . $attachment_id );
		}

		$original_size = filesize( $file_path );
		$backup_path   = $file_path . '.backup';

		// Create backup.
		if ( ! copy( $file_path, $backup_path ) ) {
			return $this->failure( 'Failed to create backup' );
		}

		try {
			$image_type = wp_check_filetype( $file_path );
			$image      = wp_get_image_editor( $file_path );

			if ( is_wp_error( $image ) ) {
				unlink( $backup_path );
				return $this->failure( 'Failed to load image: ' . $image->get_error_message() );
			}

			// Set quality.
			$image->set_quality( $quality );

			// Convert format if requested.
			if ( $format && $format !== $image_type[ 'ext' ] ) {
				$new_path = preg_replace( '/\.[^.]+$/', '.' . $format, $file_path );
				$saved    = $image->save( $new_path, 'image/' . $format );

				if ( is_wp_error( $saved ) ) {
					unlink( $backup_path );
					return $this->failure( 'Failed to convert image format: ' . $saved->get_error_message() );
				}

				// Update attachment file path.
				update_attached_file( $attachment_id, $saved[ 'path' ] );
				$file_path = $saved[ 'path' ];
			} else {
				$saved = $image->save( $file_path );

				if ( is_wp_error( $saved ) ) {
					// Restore from backup.
					copy( $backup_path, $file_path );
					unlink( $backup_path );
					return $this->failure( 'Failed to optimize image: ' . $saved->get_error_message() );
				}
			}

			$new_size      = filesize( $file_path );
			$saved_bytes   = $original_size - $new_size;
			$saved_percent = $original_size > 0 ? ( $saved_bytes / $original_size ) * 100 : 0;

			// Clean up backup.
			unlink( $backup_path );

			return $this->success(
				array(
					'attachment_id'  => $attachment_id,
					'original_size'  => $original_size,
					'new_size'       => $new_size,
					'saved_bytes'    => $saved_bytes,
					'saved_percent'  => round( $saved_percent, 2 ),
					'quality'        => $quality,
					'format_changed' => $format ? true : false,
				),
				array( 'operation' => 'optimize' )
			);

		} catch (Exception $e) {
			// Restore from backup on error.
			if ( file_exists( $backup_path ) ) {
				copy( $backup_path, $file_path );
				unlink( $backup_path );
			}
			return $this->failure( 'Image optimization failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Add watermark to an image.
	 *
	 * @since 1.0.0
	 * @return Job_Result
	 */
	private function add_watermark() {
		$attachment_id = $this->get_payload_value( 'attachment_id' );
		$watermark_id  = $this->get_payload_value( 'watermark_id' );
		$position      = $this->get_payload_value( 'position', 'bottom-right' );
		$opacity       = $this->get_payload_value( 'opacity', 50 );
		$margin        = $this->get_payload_value( 'margin', 10 );

		if ( ! $attachment_id || ! $watermark_id ) {
			return $this->failure( 'Missing attachment ID or watermark ID' );
		}

		$image_path     = get_attached_file( $attachment_id );
		$watermark_path = get_attached_file( $watermark_id );

		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return $this->failure( 'Main image file not found' );
		}

		if ( ! $watermark_path || ! file_exists( $watermark_path ) ) {
			return $this->failure( 'Watermark image file not found' );
		}

		try {
			$image     = wp_get_image_editor( $image_path );
			$watermark = wp_get_image_editor( $watermark_path );

			if ( is_wp_error( $image ) ) {
				return $this->failure( 'Failed to load main image: ' . $image->get_error_message() );
			}

			if ( is_wp_error( $watermark ) ) {
				return $this->failure( 'Failed to load watermark: ' . $watermark->get_error_message() );
			}

			$image_size     = $image->get_size();
			$watermark_size = $watermark->get_size();

			// Calculate watermark position.
			$coordinates = $this->calculate_watermark_position(
				$image_size,
				$watermark_size,
				$position,
				$margin
			);

			// Apply watermark (this is a simplified example).
			// In a real implementation, you'd need to handle different image editors and opacity.
			$result = $image->save( $image_path );

			if ( is_wp_error( $result ) ) {
				return $this->failure( 'Failed to save watermarked image: ' . $result->get_error_message() );
			}

			return $this->success(
				array(
					'attachment_id' => $attachment_id,
					'watermark_id'  => $watermark_id,
					'position'      => $position,
					'coordinates'   => $coordinates,
					'opacity'       => $opacity,
				),
				array( 'operation' => 'watermark' )
			);

		} catch (Exception $e) {
			return $this->failure( 'Watermark application failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Generate thumbnails for multiple images.
	 *
	 * @since 1.0.0
	 * @return Job_Result
	 */
	private function generate_bulk_thumbnails() {
		$attachment_ids = $this->get_payload_value( 'attachment_ids', array() );
		$sizes          = $this->get_payload_value( 'sizes', array() );

		if ( empty( $attachment_ids ) ) {
			return $this->failure( 'No attachment IDs provided' );
		}

		$processed = 0;
		$failed    = 0;
		$results   = array();

		foreach ( $attachment_ids as $attachment_id ) {
			try {
				// Create individual thumbnail job.
				$thumbnail_job = new self( array(
					'operation'     => 'thumbnail',
					'attachment_id' => $attachment_id,
					'sizes'         => $sizes,
				) );

				$result = $thumbnail_job->generate_thumbnails();

				if ( $result->is_successful() ) {
					$processed++;
					$results[ $attachment_id ] = $result->get_data();
				} else {
					$failed++;
					$results[ $attachment_id ] = array(
						'error' => $result->get_error_message(),
					);
				}

			} catch (Exception $e) {
				$failed++;
				$results[ $attachment_id ] = array(
					'error' => $e->getMessage(),
				);
			}
		}

		return $this->success(
			array(
				'total'     => count( $attachment_ids ),
				'processed' => $processed,
				'failed'    => $failed,
				'results'   => $results,
			),
			array( 'operation' => 'bulk_thumbnails' )
		);
	}

	/**
	 * Calculate watermark position coordinates.
	 *
	 * @since 1.0.0
	 * @param array  $image_size     Image dimensions.
	 * @param array  $watermark_size Watermark dimensions.
	 * @param string $position       Position string.
	 * @param int    $margin         Margin in pixels.
	 * @return array X and Y coordinates.
	 */
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
				break;
		}

		return array( 'x' => max( 0, $x ), 'y' => max( 0, $y ) );
	}

	/**
	 * Create a thumbnail generation job.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id Attachment ID.
	 * @param array $sizes         Optional specific sizes.
	 * @return Image_Processing_Job
	 */
	public static function create_thumbnail_job( $attachment_id, $sizes = array() ) {
		return new self( array(
			'operation'     => 'thumbnail',
			'attachment_id' => $attachment_id,
			'sizes'         => $sizes,
		) );
	}

	/**
	 * Create an image optimization job.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $quality       Image quality (1-100).
	 * @param string $format        Optional format conversion.
	 * @return Image_Processing_Job
	 */
	public static function create_optimization_job( $attachment_id, $quality = 85, $format = null ) {
		return new self( array(
			'operation'     => 'optimize',
			'attachment_id' => $attachment_id,
			'quality'       => $quality,
			'format'        => $format,
		) );
	}

	/**
	 * Create a watermark job.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $watermark_id  Watermark attachment ID.
	 * @param string $position      Watermark position.
	 * @param int    $opacity       Opacity percentage.
	 * @param int    $margin        Margin in pixels.
	 * @return Image_Processing_Job
	 */
	public static function create_watermark_job( $attachment_id, $watermark_id, $position = 'bottom-right', $opacity = 50, $margin = 10 ) {
		return new self( array(
			'operation'     => 'watermark',
			'attachment_id' => $attachment_id,
			'watermark_id'  => $watermark_id,
			'position'      => $position,
			'opacity'       => $opacity,
			'margin'        => $margin,
		) );
	}
}