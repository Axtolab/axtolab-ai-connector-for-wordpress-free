<?php
/**
 * Image Provider Integration.
 *
 * Server-side image generation (Google Imagen, OpenAI) and stock photo
 * search (Unsplash, Pexels). API keys stored in WordPress options,
 * images saved directly to the media library.
 *
 * @package WP_MCP_Gateway
 * @since   0.1.26
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Axtolab_AI_Connector_Image_Providers {

	/** WordPress option key for all image provider settings. */
	private const OPTION_KEY = 'axtolab_ai_connector_image_providers';

	/** Auto-cleanup: delete unconfirmed images older than this (seconds). */
	private const PENDING_IMAGE_TTL = 86400; // 24 hours

	/** Cron hook name for cleanup. */
	public const CLEANUP_HOOK = 'axtolab_ai_connector_cleanup_pending_images';

	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			do_action( 'axtolab_ai_connector_debug_log', $message );
		}
	}

	// =========================================================================
	// Settings
	// =========================================================================

	/**
	 * Get all provider settings with defaults.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$defaults = array(
			'google_imagen' => array( 'enabled' => false, 'api_key' => '', 'model' => 'imagen-3.0-generate-002' ),
			'openai'        => array( 'enabled' => false, 'api_key' => '', 'model' => 'gpt-image-1', 'quality' => 'medium' ),
			'unsplash'      => array( 'enabled' => false, 'access_key' => '' ),
			'pexels'        => array( 'enabled' => false, 'api_key' => '' ),
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return is_array( $saved ) ? array_replace_recursive( $defaults, $saved ) : $defaults;
	}

	/**
	 * Get enabled providers by type ('generation' or 'stock').
	 *
	 * @param string $type Provider type.
	 * @return array List of enabled provider keys.
	 */
	public static function get_enabled_providers( string $type ): array {
		$settings  = self::get_settings();
		$providers = array();

		if ( 'generation' === $type ) {
			if ( class_exists( 'Axtolab_AI_Connector_Free_Gates' ) && ! Axtolab_AI_Connector_Free_Gates::is_image_generation_allowed() ) {
				return array();
			}

			if ( ! empty( $settings['google_imagen']['enabled'] ) && '' !== $settings['google_imagen']['api_key'] ) {
				$providers[] = 'google_imagen';
			}
			if ( ! empty( $settings['openai']['enabled'] ) && '' !== $settings['openai']['api_key'] ) {
				$providers[] = 'openai';
			}
		} elseif ( 'stock' === $type ) {
			if ( ! empty( $settings['unsplash']['enabled'] ) && '' !== $settings['unsplash']['access_key'] ) {
				$providers[] = 'unsplash';
			}
			if ( ! empty( $settings['pexels']['enabled'] ) && '' !== $settings['pexels']['api_key'] ) {
				$providers[] = 'pexels';
			}
		}

		/**
		 * Filter the list of enabled providers for a given type. Add-on
		 * plugins (e.g. axtolab-image-generation) hook this to register
		 * additional providers like Flux or Gemini Nano-Banana.
		 *
		 * @param string[] $providers Provider keys.
		 * @param string   $type      'generation' or 'stock'.
		 */
		$providers = apply_filters( 'axtolab_ai_connector_enabled_image_providers', $providers, $type );

		return is_array( $providers ) ? array_values( array_unique( $providers ) ) : array();
	}

	// =========================================================================
	// Encryption helpers
	// =========================================================================

	/**
	 * Encrypt an API key using WordPress auth salts.
	 *
	 * @param string $plain Plaintext key.
	 * @return string Encrypted key (base64).
	 */
	private static function encrypt_key( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key    = wp_salt( 'auth' );
		$iv     = substr( md5( wp_salt( 'secure_auth' ) ), 0, 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, 0, $iv );
		return base64_encode( $cipher );
	}

	/**
	 * Decrypt an API key.
	 *
	 * @param string $encrypted Encrypted key (base64).
	 * @return string Plaintext key.
	 */
	private static function decrypt_key( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}
		$key    = wp_salt( 'auth' );
		$iv     = substr( md5( wp_salt( 'secure_auth' ) ), 0, 16 );
		$cipher = base64_decode( $encrypted );
		if ( false === $cipher ) {
			self::debug_log( 'MCP Gateway: API key decryption failed — base64_decode returned false. Keys may need to be re-entered.' );
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, 0, $iv );
		if ( false === $plain ) {
			self::debug_log( 'MCP Gateway: API key decryption failed — openssl_decrypt returned false. WordPress salts may have changed; re-enter API keys in settings.' );
			return '';
		}
		return $plain;
	}

	// =========================================================================
	// Image Generation
	// =========================================================================

	/**
	 * Generate an image using the specified or first enabled provider.
	 *
	 * @param string $prompt  Image description.
	 * @param array  $options Optional: provider, aspect_ratio, quality.
	 * @return array|WP_Error Result with media_id, url, etc.
	 */
	public static function generate_image( string $prompt, array $options = array() ) {
		if ( class_exists( 'Axtolab_AI_Connector_Free_Gates' ) ) {
			$generation_allowed = Axtolab_AI_Connector_Free_Gates::check_image_generation_allowed();
			if ( is_wp_error( $generation_allowed ) ) {
				return $generation_allowed;
			}
		}

		$providers = self::get_enabled_providers( 'generation' );
		$provider  = $options['provider'] ?? ( $providers[0] ?? null );

		if ( null === $provider || ! in_array( $provider, $providers, true ) ) {
			return new WP_Error(
				'no_image_provider',
				'No image generation providers are enabled. Configure one in WP MCP Gateway → Image Providers.',
				array( 'status' => 400 )
			);
		}

		$aspect_ratio = $options['aspect_ratio'] ?? '16:9';
		$quality      = $options['quality'] ?? 'medium';

		switch ( $provider ) {
			case 'google_imagen':
				$result = self::generate_google_imagen( $prompt, $aspect_ratio );
				break;
			case 'openai':
				$result = self::generate_openai( $prompt, $aspect_ratio, $quality );
				break;
			default:
				/**
				 * Filter: dispatch to a provider not handled in core.
				 * Add-on plugins return either:
				 *   - array{ bytes: string, format: string } on success
				 *   - WP_Error on failure
				 *   - null to pass through (uncaught provider)
				 *
				 * @param mixed  $result   Initial null.
				 * @param string $provider Provider key.
				 * @param string $prompt   User prompt.
				 * @param array  $opts     Generation options.
				 */
				$result = apply_filters(
					'axtolab_ai_connector_image_provider_dispatch',
					null,
					$provider,
					$prompt,
					array(
						'aspect_ratio' => $aspect_ratio,
						'quality'      => $quality,
					)
				);
				if ( null === $result ) {
					$result = new WP_Error( 'invalid_provider', "Unknown provider: {$provider}" );
				}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::save_to_media_library( $result['bytes'], $result['format'], $prompt, $provider );
	}

	/**
	 * Generate an image with Google Imagen.
	 *
	 * @param string $prompt       Image description.
	 * @param string $aspect_ratio Aspect ratio.
	 * @return array|WP_Error Raw bytes + format, or error.
	 */
	private static function generate_google_imagen( string $prompt, string $aspect_ratio ) {
		$settings = self::get_settings();
		$api_key  = self::decrypt_key( $settings['google_imagen']['api_key'] );
		$model    = $settings['google_imagen']['model'] ?? 'imagen-3.0-generate-002';

		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:predict',
			rawurlencode( $model )
		);

		$body = array(
			'instances'  => array( array( 'prompt' => $prompt ) ),
			'parameters' => array(
				'sampleCount'       => 1,
				'aspectRatio'       => $aspect_ratio,
				'safetyFilterLevel' => 'block_some',
			),
		);

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = $data['error']['message'] ?? 'Google Imagen API request failed';
			return new WP_Error( 'google_imagen_error', $msg, array( 'status' => $code ) );
		}

		$b64 = $data['predictions'][0]['bytesBase64Encoded'] ?? null;
		if ( ! $b64 ) {
			return new WP_Error( 'google_imagen_empty', 'No image returned from Google Imagen.' );
		}

		return array(
			'bytes'  => base64_decode( $b64 ),
			'format' => 'png',
		);
	}

	/**
	 * Generate an image with OpenAI.
	 *
	 * @param string $prompt       Image description.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param string $quality      Quality tier.
	 * @return array|WP_Error Raw bytes + format, or error.
	 */
	private static function generate_openai( string $prompt, string $aspect_ratio, string $quality ) {
		$settings = self::get_settings();
		$api_key  = self::decrypt_key( $settings['openai']['api_key'] );
		$model    = $settings['openai']['model'] ?? 'gpt-image-1';

		$size_map = array(
			'1:1'  => '1024x1024',
			'16:9' => '1536x1024',
			'9:16' => '1024x1536',
			'4:3'  => '1536x1024',
			'3:4'  => '1024x1536',
		);
		$size = $size_map[ $aspect_ratio ] ?? '1536x1024';

		$body = array(
			'model'         => $model,
			'prompt'        => $prompt,
			'n'             => 1,
			'size'          => $size,
			'quality'       => $quality,
			'output_format' => 'png',
		);

		$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = $data['error']['message'] ?? 'OpenAI API request failed';
			return new WP_Error( 'openai_error', $msg, array( 'status' => $code ) );
		}

		$b64 = $data['data'][0]['b64_json'] ?? null;
		if ( ! $b64 ) {
			return new WP_Error( 'openai_empty', 'No image returned from OpenAI.' );
		}

		return array(
			'bytes'  => base64_decode( $b64 ),
			'format' => 'png',
		);
	}

	// =========================================================================
	// Stock Photo Search
	// =========================================================================

	/**
	 * Search for stock photos.
	 *
	 * @param string $query   Search terms.
	 * @param array  $options Optional: provider, orientation, per_page.
	 * @return array|WP_Error List of results or error.
	 */
	public static function search_stock_photos( string $query, array $options = array() ) {
		$providers   = self::get_enabled_providers( 'stock' );
		$provider    = $options['provider'] ?? ( $providers[0] ?? null );
		$orientation = $options['orientation'] ?? null;
		$per_page    = min( max( intval( $options['per_page'] ?? 5 ), 1 ), 10 );

		if ( null === $provider || ! in_array( $provider, $providers, true ) ) {
			return new WP_Error(
				'no_stock_provider',
				'No stock photo providers are enabled. Configure one in WP MCP Gateway → Image Providers.',
				array( 'status' => 400 )
			);
		}

		switch ( $provider ) {
			case 'unsplash':
				return self::search_unsplash( $query, $orientation, $per_page );
			case 'pexels':
				return self::search_pexels( $query, $orientation, $per_page );
			default:
				return new WP_Error( 'invalid_provider', "Unknown provider: {$provider}" );
		}
	}

	/**
	 * Search Unsplash.
	 */
	private static function search_unsplash( string $query, ?string $orientation, int $per_page ) {
		$settings   = self::get_settings();
		$access_key = self::decrypt_key( $settings['unsplash']['access_key'] );

		$args = array(
			'query'     => $query,
			'per_page'  => $per_page,
			'client_id' => $access_key,
		);
		if ( $orientation ) {
			$args['orientation'] = 'square' === $orientation ? 'squarish' : $orientation;
		}

		$url      = add_query_arg( $args, 'https://api.unsplash.com/search/photos' );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error( 'unsplash_error', $data['errors'][0] ?? 'Unsplash API error', array( 'status' => $code ) );
		}

		$results = array();
		foreach ( $data['results'] ?? array() as $photo ) {
			$results[] = array(
				'provider'          => 'unsplash',
				'provider_id'       => $photo['id'],
				'preview_url'       => $photo['urls']['regular'] ?? $photo['urls']['small'],
				'full_url'          => $photo['urls']['full'],
				'description'       => $photo['alt_description'] ?? $photo['description'] ?? '',
				'photographer'      => $photo['user']['name'] ?? 'Unknown',
				'photographer_url'  => $photo['user']['links']['html'] ?? '',
				'attribution'       => sprintf( 'Photo by %s on Unsplash', $photo['user']['name'] ?? 'Unknown' ),
				'width'             => $photo['width'] ?? 0,
				'height'            => $photo['height'] ?? 0,
				'download_location' => $photo['links']['download_location'] ?? '',
			);
		}

		return $results;
	}

	/**
	 * Search Pexels.
	 */
	private static function search_pexels( string $query, ?string $orientation, int $per_page ) {
		$settings = self::get_settings();
		$api_key  = self::decrypt_key( $settings['pexels']['api_key'] );

		$args = array(
			'query'    => $query,
			'per_page' => $per_page,
		);
		if ( $orientation ) {
			$args['orientation'] = $orientation;
		}

		$url      = add_query_arg( $args, 'https://api.pexels.com/v1/search' );
		$response = wp_remote_get( $url, array(
			'headers' => array( 'Authorization' => $api_key ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error( 'pexels_error', 'Pexels API error', array( 'status' => $code ) );
		}

		$results = array();
		foreach ( $data['photos'] ?? array() as $photo ) {
			$results[] = array(
				'provider'         => 'pexels',
				'provider_id'      => (string) $photo['id'],
				'preview_url'      => $photo['src']['medium'] ?? $photo['src']['small'],
				'full_url'         => $photo['src']['original'],
				'description'      => $photo['alt'] ?? '',
				'photographer'     => $photo['photographer'] ?? 'Unknown',
				'photographer_url' => $photo['photographer_url'] ?? '',
				'attribution'      => sprintf( 'Photo by %s on Pexels', $photo['photographer'] ?? 'Unknown' ),
				'width'            => $photo['width'] ?? 0,
				'height'           => $photo['height'] ?? 0,
			);
		}

		return $results;
	}

	// =========================================================================
	// Stock Photo Import
	// =========================================================================

	/**
	 * Import a stock photo into the media library.
	 *
	 * @param string $provider    Provider key ('unsplash' or 'pexels').
	 * @param string $provider_id Photo ID at the provider.
	 * @param array  $options     Optional: alt_text override.
	 * @return array|WP_Error Result with media_id, url, attribution, etc.
	 */
	public static function import_stock_photo( string $provider, string $provider_id, array $options = array() ) {
		$settings = self::get_settings();

		switch ( $provider ) {
			case 'unsplash':
				$access_key = self::decrypt_key( $settings['unsplash']['access_key'] );

				// Trigger the required download tracking endpoint.
				$track_url = sprintf(
					'https://api.unsplash.com/photos/%s/download?client_id=%s',
					rawurlencode( $provider_id ),
					rawurlencode( $access_key )
				);
				wp_remote_get( $track_url, array( 'timeout' => 10 ) );

				// Get photo details.
				$photo_url = sprintf(
					'https://api.unsplash.com/photos/%s?client_id=%s',
					rawurlencode( $provider_id ),
					rawurlencode( $access_key )
				);
				$photo_response = wp_remote_get( $photo_url, array( 'timeout' => 15 ) );
				if ( is_wp_error( $photo_response ) ) {
					return $photo_response;
				}
				$photo = json_decode( wp_remote_retrieve_body( $photo_response ), true );

				$download_url = $photo['urls']['regular'] ?? $photo['urls']['full'];
				$description  = $photo['alt_description'] ?? $photo['description'] ?? '';
				$attribution  = sprintf( 'Photo by %s on Unsplash', $photo['user']['name'] ?? 'Unknown' );
				break;

			case 'pexels':
				$api_key = self::decrypt_key( $settings['pexels']['api_key'] );

				$photo_url = sprintf( 'https://api.pexels.com/v1/photos/%s', rawurlencode( $provider_id ) );
				$photo_response = wp_remote_get( $photo_url, array(
					'headers' => array( 'Authorization' => $api_key ),
					'timeout' => 15,
				) );
				if ( is_wp_error( $photo_response ) ) {
					return $photo_response;
				}
				$photo = json_decode( wp_remote_retrieve_body( $photo_response ), true );

				$download_url = $photo['src']['original'] ?? $photo['src']['large2x'];
				$description  = $photo['alt'] ?? '';
				$attribution  = sprintf( 'Photo by %s on Pexels', $photo['photographer'] ?? 'Unknown' );
				break;

			default:
				return new WP_Error( 'invalid_provider', "Unknown stock provider: {$provider}" );
		}

		// Download the image.
		$image_response = wp_remote_get( $download_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $image_response ) ) {
			return $image_response;
		}

		$bytes     = wp_remote_retrieve_body( $image_response );
		$mime_type = wp_remote_retrieve_header( $image_response, 'content-type' );
		$ext       = 'jpeg';
		if ( str_contains( $mime_type, 'png' ) ) {
			$ext = 'png';
		} elseif ( str_contains( $mime_type, 'webp' ) ) {
			$ext = 'webp';
		}

		$alt_text = $options['alt_text'] ?? $description;
		$filename = sprintf( '%s-%s.%s', $provider, $provider_id, $ext );
		$upload   = wp_upload_bits( $filename, null, $bytes );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'] );
		}

		$filetype   = wp_check_filetype( $upload['file'] );
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field( $alt_text ?: $filename ),
			'post_content'   => sanitize_text_field( $attribution ),
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $metadata );

		update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		update_post_meta( $attach_id, '_axtolab_ai_connector_image_provider', $provider );
		update_post_meta( $attach_id, '_axtolab_ai_connector_image_provider_id', $provider_id );
		update_post_meta( $attach_id, '_axtolab_ai_connector_image_attribution', $attribution );

		$url = wp_get_attachment_url( $attach_id );

		return array(
			'media_id'    => $attach_id,
			'url'         => $url,
			'width'       => $metadata['width'] ?? 0,
			'height'      => $metadata['height'] ?? 0,
			'attribution' => $attribution,
			'provider'    => $provider,
		);
	}

	// =========================================================================
	// Save generated image to media library
	// =========================================================================

	/**
	 * Save raw image bytes to the WordPress media library.
	 *
	 * @param string $bytes    Raw image bytes.
	 * @param string $format   Image format (png, jpeg, webp).
	 * @param string $prompt   Generation prompt (used for alt text).
	 * @param string $provider Provider name (stored in meta).
	 * @return array|WP_Error Result with media_id, url, etc.
	 */
	private static function save_to_media_library( string $bytes, string $format, string $prompt, string $provider ) {
		$ext      = in_array( $format, array( 'png', 'jpeg', 'webp' ), true ) ? $format : 'png';
		$filename = sprintf( 'ai-generated-%s-%s.%s', $provider, wp_generate_password( 8, false ), $ext );
		$upload   = wp_upload_bits( $filename, null, $bytes );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'] );
		}

		$filetype   = wp_check_filetype( $upload['file'] );
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field( substr( $prompt, 0, 200 ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $metadata );

		update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( substr( $prompt, 0, 200 ) ) );
		update_post_meta( $attach_id, '_axtolab_ai_connector_image_provider', $provider );
		update_post_meta( $attach_id, '_axtolab_ai_connector_image_prompt', $prompt );
		update_post_meta( $attach_id, '_axtolab_ai_connector_image_status', 'pending' );
		update_post_meta( $attach_id, '_axtolab_ai_connector_image_generated_at', current_time( 'mysql', true ) );

		$url = wp_get_attachment_url( $attach_id );

		return array(
			'media_id' => $attach_id,
			'url'      => $url,
			'width'    => $metadata['width'] ?? 0,
			'height'   => $metadata['height'] ?? 0,
			'provider' => $provider,
			'filename' => $filename,
		);
	}

	// =========================================================================
	// Confirm / Cleanup
	// =========================================================================

	/**
	 * Confirm a generated image (prevents auto-cleanup).
	 *
	 * @param int $media_id Attachment ID.
	 * @return bool True if confirmed, false if not found/not pending.
	 */
	public static function confirm_image( int $media_id ): bool {
		$status = get_post_meta( $media_id, '_axtolab_ai_connector_image_status', true );
		if ( 'pending' !== $status ) {
			return false;
		}
		update_post_meta( $media_id, '_axtolab_ai_connector_image_status', 'confirmed' );
		return true;
	}

	/**
	 * Cleanup pending images older than PENDING_IMAGE_TTL.
	 * Called by WP-Cron.
	 */
	public static function cleanup_pending_images(): void {
		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 50,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_axtolab_ai_connector_image_status',
					'value' => 'pending',
				),
				array(
					'key'     => '_axtolab_ai_connector_image_generated_at',
					'value'   => gmdate( 'Y-m-d H:i:s', time() - self::PENDING_IMAGE_TTL ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			),
		) );

		foreach ( $query->posts as $post ) {
			wp_delete_attachment( $post->ID, true );
		}
	}

	// =========================================================================
	// Test Connection
	// =========================================================================

	/**
	 * Validate an API key with a minimal API call.
	 *
	 * @param string $provider Provider key.
	 * @return array|WP_Error Success array or error.
	 */
	public static function test_connection( string $provider ) {
		$settings = self::get_settings();

		switch ( $provider ) {
			case 'google_imagen':
				$api_key  = self::decrypt_key( $settings['google_imagen']['api_key'] );
				$response = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models', array(
					'headers' => array( 'x-goog-api-key' => $api_key ),
					'timeout' => 10,
				) );
				break;

			case 'openai':
				$api_key  = self::decrypt_key( $settings['openai']['api_key'] );
				$response = wp_remote_get( 'https://api.openai.com/v1/models', array(
					'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
					'timeout' => 10,
				) );
				break;

			case 'unsplash':
				$access_key = self::decrypt_key( $settings['unsplash']['access_key'] );
				$url        = 'https://api.unsplash.com/photos/random?client_id=' . rawurlencode( $access_key ) . '&count=1';
				$response   = wp_remote_get( $url, array( 'timeout' => 10 ) );
				break;

			case 'pexels':
				$api_key  = self::decrypt_key( $settings['pexels']['api_key'] );
				$response = wp_remote_get( 'https://api.pexels.com/v1/curated?per_page=1', array(
					'headers' => array( 'Authorization' => $api_key ),
					'timeout' => 10,
				) );
				break;

			default:
				return new WP_Error( 'invalid_provider', "Unknown provider: {$provider}" );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'provider' => $provider );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = $body['error']['message'] ?? $body['errors'][0] ?? 'Connection test failed';
		return new WP_Error( 'connection_failed', $msg, array( 'status' => $code ) );
	}

	/**
	 * Test a provider connection using a raw API key (not yet saved).
	 *
	 * @param string $provider Provider key.
	 * @param string $api_key  Raw plaintext API key.
	 * @return array|WP_Error
	 */
	public static function test_connection_with_key( string $provider, string $api_key ) {
		switch ( $provider ) {
			case 'google_imagen':
				$response = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models', array(
					'headers' => array( 'x-goog-api-key' => $api_key ),
					'timeout' => 10,
				) );
				break;

			case 'openai':
				$response = wp_remote_get( 'https://api.openai.com/v1/models', array(
					'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
					'timeout' => 10,
				) );
				break;

			case 'unsplash':
				$url      = 'https://api.unsplash.com/photos/random?client_id=' . rawurlencode( $api_key ) . '&count=1';
				$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
				break;

			case 'pexels':
				$response = wp_remote_get( 'https://api.pexels.com/v1/curated?per_page=1', array(
					'headers' => array( 'Authorization' => $api_key ),
					'timeout' => 10,
				) );
				break;

			default:
				return new WP_Error( 'invalid_provider', "Unknown provider: {$provider}" );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'provider' => $provider );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = $body['error']['message'] ?? $body['errors'][0] ?? 'Connection test failed';
		return new WP_Error( 'connection_failed', $msg, array( 'status' => $code ) );
	}

	// =========================================================================
	// Admin settings save helper
	// =========================================================================

	/**
	 * Save provider settings from admin AJAX, encrypting API keys.
	 *
	 * @param array $raw_settings Raw settings from POST.
	 * @return void
	 */
	public static function save_settings( array $raw_settings ): void {
		$current = self::get_settings();

		foreach ( array( 'google_imagen', 'openai', 'unsplash', 'pexels' ) as $provider ) {
			if ( ! isset( $raw_settings[ $provider ] ) || ! is_array( $raw_settings[ $provider ] ) ) {
				continue;
			}

			$input = $raw_settings[ $provider ];

			$current[ $provider ]['enabled'] = ! empty( $input['enabled'] );

			// Encrypt API keys — only update if a new value was provided.
			// Sentinel '__CLEAR__' explicitly removes the stored key.
			$key_field = 'unsplash' === $provider ? 'access_key' : 'api_key';
			if ( isset( $input[ $key_field ] ) ) {
				$val = $input[ $key_field ];
				if ( '__CLEAR__' === $val ) {
					$current[ $provider ][ $key_field ] = '';
				} elseif ( '' !== $val ) {
					$current[ $provider ][ $key_field ] = self::encrypt_key( sanitize_text_field( $val ) );
				}
			}

			// Provider-specific settings.
			if ( 'google_imagen' === $provider && isset( $input['model'] ) ) {
				$current[ $provider ]['model'] = sanitize_text_field( $input['model'] );
			}
			if ( 'openai' === $provider ) {
				if ( isset( $input['model'] ) ) {
					$current[ $provider ]['model'] = sanitize_text_field( $input['model'] );
				}
				if ( isset( $input['quality'] ) ) {
					$current[ $provider ]['quality'] = sanitize_text_field( $input['quality'] );
				}
			}
		}

		update_option( self::OPTION_KEY, $current, false );
	}
}

if ( ! class_exists( 'MCP_Gateway_Image_Providers', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Image_Providers', 'MCP_Gateway_Image_Providers' );
}
