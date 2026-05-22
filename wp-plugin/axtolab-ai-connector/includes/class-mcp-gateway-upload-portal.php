<?php
/**
 * Upload Portal — secure, temporary drag-and-drop file uploads.
 *
 * Provides a standalone HTML page (no WordPress theme) where users can
 * drag-and-drop images into the WordPress media library via a time-limited,
 * token-secured URL. Works with any MCP client (Claude Desktop, ChatGPT, etc.).
 *
 * Security model: presigned-URL style. Token is 64-char hex (32 random bytes),
 * stored as SHA-256 hash in a WordPress transient with 15-minute TTL.
 *
 * @package WP_MCP_Gateway
 * @since   0.1.27
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_Upload_Portal', false ) ) {
	return;
}

class Axtolab_AI_Connector_Upload_Portal {

	const SESSION_TTL       = 900;   // 15 minutes.
	const MAX_FILES         = 20;
	const MAX_SESSION_MB    = 100;
	const MAX_SESSIONS_HOUR = 10;
	const TRANSIENT_PREFIX  = '_axtolab_ai_connector_upload_session_';
	const SID_PREFIX        = '_axtolab_ai_connector_upload_sid_';
	const NONCE_ACTION      = 'axtolab_ai_connector_upload_portal';

	/**
	 * Allowed MIME types for upload portal.
	 */
	private static $allowed_mimes = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/svg+xml',
	);

	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			do_action( 'axtolab_ai_connector_debug_log', $message );
		}
	}

	// =========================================================================
	// Session Management
	// =========================================================================

	/**
	 * Create a new upload session.
	 *
	 * @param int         $created_by The user ID that created this session.
	 * @param string|null $client_ip  Optional — lock session to this IP.
	 * @return array|WP_Error { session_id, url, expires_at } or WP_Error.
	 */
	public static function create_session( $created_by, $client_ip = null ) {
		// Rate limit: max sessions per hour per user.
		$rate_key = 'axtolab_ai_connector_upload_rate_' . $created_by;
		$count    = (int) get_transient( $rate_key );
		if ( $count >= self::MAX_SESSIONS_HOUR ) {
			return new WP_Error(
				'rate_limited',
				'Too many upload sessions. Maximum ' . self::MAX_SESSIONS_HOUR . ' per hour.',
				array( 'status' => 429 )
			);
		}
		set_transient( $rate_key, $count + 1, 3600 );

		// Generate cryptographic token in UUID-style format (WAF-friendly).
		// Raw hex triggers Cloudflare/Sucuri WAF rules; dashed format does not.
		$raw_hex    = bin2hex( random_bytes( 32 ) );
		$token      = sprintf(
			'%s-%s-%s-%s-%s-%s-%s-%s',
			substr( $raw_hex, 0, 8 ),
			substr( $raw_hex, 8, 8 ),
			substr( $raw_hex, 16, 8 ),
			substr( $raw_hex, 24, 8 ),
			substr( $raw_hex, 32, 8 ),
			substr( $raw_hex, 40, 8 ),
			substr( $raw_hex, 48, 8 ),
			substr( $raw_hex, 56, 8 )
		);
		$token_hash = hash( 'sha256', $token );
		$session_id = wp_generate_uuid4();
		$expires_at = time() + self::SESSION_TTL;

		$session = array(
			'session_id'   => $session_id,
			'created_by'   => $created_by,
			'created_at'   => time(),
			'expires_at'   => $expires_at,
			'client_ip'    => $client_ip,
			'uploads'      => array(),
			'upload_count' => 0,
			'total_bytes'  => 0,
			'status'       => 'active',
		);

		// Store session keyed by token hash.
		set_transient( self::TRANSIENT_PREFIX . $token_hash, $session, self::SESSION_TTL );

		// Store reverse index: session_id → token_hash (for lookup by AI).
		set_transient( self::SID_PREFIX . $session_id, $token_hash, self::SESSION_TTL );

		$url = add_query_arg( 'token', $token, rest_url( 'axtolab-ai-connector/v1/upload/portal' ) );

		return array(
			'session_id' => $session_id,
			'url'        => $url,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Validate a session token and return session data.
	 *
	 * @param string      $token     The 64-char hex token.
	 * @param string|null $client_ip Optional — check IP binding.
	 * @return array|WP_Error Session data or WP_Error.
	 */
	public static function validate_session( $token, $client_ip = null ) {
		$token_hash = hash( 'sha256', $token );
		$session    = get_transient( self::TRANSIENT_PREFIX . $token_hash );

		if ( false === $session || ! is_array( $session ) ) {
			return new WP_Error( 'invalid_session', 'Upload session is invalid or has expired.', array( 'status' => 404 ) );
		}

		// Double-check expiry (transient TTL should handle this, but be safe).
		if ( time() > $session['expires_at'] ) {
			delete_transient( self::TRANSIENT_PREFIX . $token_hash );
			return new WP_Error( 'session_expired', 'Upload session has expired.', array( 'status' => 410 ) );
		}

		// Check IP binding if enabled.
		if ( ! empty( $session['client_ip'] ) && null !== $client_ip && $session['client_ip'] !== $client_ip ) {
			return new WP_Error( 'ip_mismatch', 'IP address does not match session.', array( 'status' => 403 ) );
		}

		// Check file count limit.
		if ( $session['upload_count'] >= self::MAX_FILES ) {
			return new WP_Error( 'max_files_reached', 'Maximum file limit reached (' . self::MAX_FILES . ').', array( 'status' => 400 ) );
		}

		// Check session status.
		if ( 'active' !== $session['status'] ) {
			return new WP_Error( 'session_closed', 'Upload session is no longer active.', array( 'status' => 400 ) );
		}

		return $session;
	}

	/**
	 * Handle a file upload within a session.
	 *
	 * @param string      $token     The 64-char hex session token.
	 * @param array       $file      $_FILES entry for the uploaded file.
	 * @param string|null $client_ip Uploader's IP address.
	 * @return array|WP_Error { media_id, filename, url, thumbnail_url } or WP_Error.
	 */
	public static function handle_upload( $token, $file, $client_ip = null ) {
		// Validate session.
		$session = self::validate_session( $token, $client_ip );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// Check for upload errors.
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::debug_log( sprintf( 'MCP Upload Portal: No valid file uploaded for session %s.', $session['session_id'] ) );
			return new WP_Error( 'upload_error', 'No valid file uploaded.', array( 'status' => 400 ) );
		}

		if ( ! empty( $file['error'] ) ) {
			self::debug_log( sprintf( 'MCP Upload Portal: PHP upload error %d for session %s, file %s.', intval( $file['error'] ), $session['session_id'], isset( $file['name'] ) ? $file['name'] : 'unknown' ) );
			return new WP_Error( 'upload_error', 'File upload error code: ' . intval( $file['error'] ), array( 'status' => 400 ) );
		}

		// Validate MIME type via finfo (server-side, never trust client headers).
		$finfo     = new finfo( FILEINFO_MIME_TYPE );
		$real_mime = $finfo->file( $file['tmp_name'] );

		if ( ! in_array( $real_mime, self::$allowed_mimes, true ) ) {
			self::debug_log( sprintf( 'MCP Upload Portal: MIME type rejected (%s) for session %s, file %s.', $real_mime, $session['session_id'], isset( $file['name'] ) ? $file['name'] : 'unknown' ) );
			return new WP_Error(
				'invalid_mime',
				'File type not allowed. Accepted: JPEG, PNG, WebP, GIF, SVG.',
				array( 'status' => 400 )
			);
		}

		// Check individual file size.
		$config      = Axtolab_AI_Connector_Config::get();
		$max_size_mb = isset( $config['media_max_size_mb'] ) ? (int) $config['media_max_size_mb'] : 10;
		$max_bytes   = $max_size_mb * 1024 * 1024;
		$file_size   = filesize( $file['tmp_name'] );

		if ( $file_size > $max_bytes ) {
			self::debug_log( sprintf( 'MCP Upload Portal: File too large (%s bytes, max %s) for session %s, file %s.', $file_size, $max_bytes, $session['session_id'], isset( $file['name'] ) ? $file['name'] : 'unknown' ) );
			return new WP_Error(
				'file_too_large',
				'File exceeds maximum size of ' . $max_size_mb . 'MB.',
				array( 'status' => 400 )
			);
		}

		// Check total session bytes.
		$max_session_bytes = self::MAX_SESSION_MB * 1024 * 1024;
		if ( ( $session['total_bytes'] + $file_size ) > $max_session_bytes ) {
			self::debug_log( sprintf( 'MCP Upload Portal: Session size exceeded (%s + %s > %s) for session %s.', $session['total_bytes'], $file_size, $max_session_bytes, $session['session_id'] ) );
			return new WP_Error(
				'session_size_exceeded',
				'Total upload size exceeds session limit of ' . self::MAX_SESSION_MB . 'MB.',
				array( 'status' => 400 )
			);
		}

		// Sanitize SVG files to strip scripts, event handlers, and external references.
		if ( 'image/svg+xml' === $real_mime ) {
			$svg_clean = self::sanitize_svg( $file['tmp_name'] );
			if ( is_wp_error( $svg_clean ) ) {
				self::debug_log( sprintf( 'MCP Upload Portal: SVG sanitization failed for session %s — %s', $session['session_id'], $svg_clean->get_error_message() ) );
				return $svg_clean;
			}
		}

		// Sanitize filename.
		$filename = sanitize_file_name( basename( $file['name'] ) );
		$filename = str_replace( array( '/', '\\' ), '', $filename );

		// Prepare file for wp_handle_upload.
		$upload_file = array(
			'name'     => $filename,
			'type'     => $real_mime,
			'tmp_name' => $file['tmp_name'],
			'error'    => 0,
			'size'     => $file_size,
		);

		// Temporarily restrict allowed MIME types for this upload.
		$mime_filter = function () {
			return array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
				'gif'          => 'image/gif',
				'svg'          => 'image/svg+xml',
			);
		};
		add_filter( 'upload_mimes', $mime_filter, 999 );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Set the service account as current user for upload permissions.
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
		if ( ! $service_user_id || ! get_user_by( 'id', $service_user_id ) ) {
			remove_filter( 'upload_mimes', $mime_filter, 999 );
			self::debug_log( 'MCP Upload Portal: Service account not found (ID: ' . $service_user_id . '). Visit the AI Connector settings page and click "Create service user".' );
			return new WP_Error( 'service_account_missing', 'AI Connector service user has not been created yet. Open the AI Connector settings page in wp-admin and click "Create service user".', array( 'status' => 500 ) );
		}

		$previous_user_id = get_current_user_id();
		wp_set_current_user( $service_user_id );

		$upload = wp_handle_upload(
			$upload_file,
			array(
				'test_form' => false,
				'action'    => 'axtolab_ai_connector_upload',
			)
		);

		// Restore previous user context.
		wp_set_current_user( $previous_user_id );

		remove_filter( 'upload_mimes', $mime_filter, 999 );

		if ( isset( $upload['error'] ) ) {
			self::debug_log( sprintf( 'MCP Upload Portal: wp_handle_upload failed for session %s, file %s — %s', $session['session_id'], $filename, $upload['error'] ) );
			return new WP_Error( 'upload_failed', $upload['error'], array( 'status' => 500 ) );
		}

		// Create WordPress attachment.
		$attachment = array(
			'post_mime_type' => $real_mime,
			'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			self::debug_log( sprintf( 'MCP Upload Portal: wp_insert_attachment failed for session %s, file %s — %s', $session['session_id'], $filename, $attachment_id->get_error_message() ) );
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Tag with session meta for tracking.
		update_post_meta( $attachment_id, '_axtolab_ai_connector_upload_session', $session['session_id'] );

		// Build upload record.
		$record = self::to_upload_record( $attachment_id, $filename );

		// Update session transient.
		$token_hash              = hash( 'sha256', $token );
		$session['uploads'][]    = $record;
		$session['upload_count'] = $session['upload_count'] + 1;
		$session['total_bytes']  = $session['total_bytes'] + $file_size;

		$remaining_ttl = max( 1, $session['expires_at'] - time() );
		set_transient( self::TRANSIENT_PREFIX . $token_hash, $session, $remaining_ttl );

		return $record;
	}

	/**
	 * Get all uploads from a session (called by AI after user finishes).
	 *
	 * @param string $session_id The session UUID.
	 * @param int    $created_by Must match the user who created the session.
	 * @return array|WP_Error Array of upload records or WP_Error.
	 */
	public static function get_session_uploads( $session_id, $created_by ) {
		// Look up token hash via reverse index.
		$token_hash = get_transient( self::SID_PREFIX . $session_id );
		if ( false === $token_hash ) {
			return new WP_Error( 'session_not_found', 'Upload session not found or expired.', array( 'status' => 404 ) );
		}

		$session = get_transient( self::TRANSIENT_PREFIX . $token_hash );
		if ( false === $session || ! is_array( $session ) ) {
			return new WP_Error( 'session_not_found', 'Upload session not found or expired.', array( 'status' => 404 ) );
		}

		// Verify ownership.
		if ( (int) $session['created_by'] !== (int) $created_by ) {
			return new WP_Error( 'forbidden', 'You do not own this upload session.', array( 'status' => 403 ) );
		}

		// Mark session as completed.
		$session['status'] = 'completed';
		$remaining_ttl     = max( 1, $session['expires_at'] - time() );
		set_transient( self::TRANSIENT_PREFIX . $token_hash, $session, $remaining_ttl );

		return array(
			'session_id'   => $session['session_id'],
			'status'       => $session['status'],
			'upload_count' => $session['upload_count'],
			'uploads'      => $session['uploads'],
		);
	}

	// =========================================================================
	// Upload Page Rendering
	// =========================================================================

	/**
	 * Render the standalone upload page HTML.
	 *
	 * Called from the REST /upload/portal endpoint.
	 * Outputs a full HTML document and calls die().
	 *
	 * @param string $token The dashed-hex session token (71 chars).
	 * @return void
	 */
	public static function render_upload_page( $token ) {
		// Override REST API content type — this endpoint serves HTML, not JSON.
		// Must be set before any output, including the expired-session page.
		header( 'Content-Type: text/html; charset=utf-8' );

		// Security headers.
		$csp_nonce = bin2hex( random_bytes( 16 ) );

		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'" );

		try {
			// Validate session.
			$session = self::validate_session( $token );

			if ( is_wp_error( $session ) ) {
				self::render_expired_page( $csp_nonce );
				die();
			}

			// Generate WordPress nonce tied to this session token.
			$token_hash = hash( 'sha256', $token );
			$wp_nonce   = wp_create_nonce( self::NONCE_ACTION . '_' . $token_hash );
			$upload_url = rest_url( 'axtolab-ai-connector/v1/upload/file' );
			$site_name  = get_bloginfo( 'name' );
			$max_files  = self::MAX_FILES;
			$config     = Axtolab_AI_Connector_Config::get();
			$max_size   = isset( $config['media_max_size_mb'] ) ? (int) $config['media_max_size_mb'] : 10;
			$expires_at = $session['expires_at'];
			$remaining  = $session['upload_count'];

			self::render_html( $csp_nonce, $token, $wp_nonce, $upload_url, $site_name, $max_files, $max_size, $expires_at, $remaining );
		} catch ( \Exception $e ) {
			self::debug_log( 'MCP Upload Portal render error: ' . $e->getMessage() );
			self::render_error_page( $csp_nonce );
		} catch ( \Error $e ) {
			self::debug_log( 'MCP Upload Portal fatal error: ' . $e->getMessage() );
			self::render_error_page( $csp_nonce );
		}
		die();
	}

	/**
	 * Render the expired/error page.
	 *
	 * @param string $csp_nonce Retained for backwards-compatible call signatures.
	 * @return void
	 */
	private static function render_expired_page( $csp_nonce ) {
		?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Session Expired</title>
		<?php self::print_upload_portal_styles(); ?>
</head>
<body class="axtolab-upload-portal-status">
	<div class="card">
		<div class="icon">&#128337;</div>
		<h1>Session Expired</h1>
		<p>This upload session has expired or is invalid.</p>
		<p style="margin-top:12px;">Please request a new upload link from your AI assistant.</p>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Render a generic error page (e.g. uncaught exception).
	 *
	 * @param string $csp_nonce Retained for backwards-compatible call signatures.
	 * @return void
	 */
	private static function render_error_page( $csp_nonce ) {
		?>
		<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Something Went Wrong</title>
		<?php self::print_upload_portal_styles(); ?>
</head>
<body class="axtolab-upload-portal-status">
	<div class="card">
		<div class="icon">&#9888;</div>
		<h1>Something Went Wrong</h1>
		<p>An unexpected error occurred while loading the upload page.</p>
		<p style="margin-top:12px;">Please request a new upload link from your AI assistant.</p>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Render the main upload page HTML.
	 *
	 * @param string $csp_nonce  CSP nonce for inline scripts.
	 * @param string $token      Session token.
	 * @param string $wp_nonce   WordPress CSRF nonce.
	 * @param string $upload_url REST endpoint URL for file uploads.
	 * @param string $site_name  WordPress site name.
	 * @param int    $max_files  Maximum files per session.
	 * @param int    $max_size   Maximum file size in MB.
	 * @param int    $expires_at Unix timestamp when session expires.
	 * @param int    $existing   Number of files already uploaded in this session.
	 * @return void
	 */
	private static function render_html( $csp_nonce, $token, $wp_nonce, $upload_url, $site_name, $max_files, $max_size, $expires_at, $existing ) {
		$site_name_esc  = esc_html( $site_name );
		$upload_url_esc = esc_url( $upload_url );
		?>
		<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Upload Files &mdash; <?php echo esc_html( $site_name_esc ); ?></title>
		<?php self::print_upload_portal_styles(); ?>
</head>
<body class="axtolab-upload-portal-page">
	<div class="upload-portal" id="portal"
		data-token="<?php echo esc_attr( $token ); ?>"
		data-nonce="<?php echo esc_attr( $wp_nonce ); ?>"
		data-upload-url="<?php echo esc_attr( $upload_url_esc ); ?>"
		data-max-files="<?php echo esc_attr( (string) intval( $max_files ) ); ?>"
		data-max-size-mb="<?php echo esc_attr( (string) intval( $max_size ) ); ?>"
		data-expires-at="<?php echo esc_attr( (string) intval( $expires_at ) ); ?>"
		data-existing="<?php echo esc_attr( (string) intval( $existing ) ); ?>">
		<div class="header">
			<h1>Upload Files</h1>
			<p class="subtitle">Drag and drop images or click to browse</p>
			<p class="meta">
				Session expires in <span class="countdown" id="countdown">--:--</span>
				&middot; <span id="file-count"><?php echo intval( $existing ); ?></span> / <?php echo intval( $max_files ); ?> files
			</p>
		</div>

		<div class="dropzone" id="dropzone">
			<svg class="dropzone-icon" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
				<path d="M8 32l8-8 6 6 12-12 6 6" stroke-linecap="round" stroke-linejoin="round"/>
				<rect x="4" y="4" width="40" height="40" rx="4" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M24 18v12M18 24l6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<p>Drop images here</p>
			<p class="hint">JPEG, PNG, WebP, GIF, SVG &middot; Max <?php echo intval( $max_size ); ?>MB each</p>
			<button class="browse-btn" type="button" id="browse-btn">Browse Files</button>
			<input type="file" id="file-input" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml" hidden>
		</div>

		<div class="uploads-grid" id="uploads-grid"></div>

		<div class="error-message" id="error-message"></div>

		<div class="actions" id="actions">
			<button class="done-btn" type="button" id="done-btn">Done &mdash; Close Page</button>
			<p class="done-message" id="done-message">You can safely close this page. Your AI assistant can now access the uploaded files.</p>
		</div>
	</div>

		<?php self::print_upload_portal_script(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Print upload portal styles through WordPress' style API.
	 *
	 * @return void
	 */
	private static function print_upload_portal_styles(): void {
		wp_enqueue_style(
			'axtolab-ai-connector-upload-portal',
			AXTOLAB_AI_CONNECTOR_URL . 'assets/upload-portal.css',
			array(),
			AXTOLAB_AI_CONNECTOR_VERSION
		);
		wp_print_styles( 'axtolab-ai-connector-upload-portal' );
	}

	/**
	 * Print upload portal JavaScript through WordPress' script API.
	 *
	 * @return void
	 */
	private static function print_upload_portal_script(): void {
		wp_enqueue_script(
			'axtolab-ai-connector-upload-portal',
			AXTOLAB_AI_CONNECTOR_URL . 'assets/upload-portal.js',
			array(),
			AXTOLAB_AI_CONNECTOR_VERSION,
			false
		);
		wp_print_scripts( 'axtolab-ai-connector-upload-portal' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * wp_handle_upload_prefilter / wp_handle_sideload_prefilter callback.
	 *
	 * Defensively sanitizes any SVG that flows through WordPress's standard
	 * upload pipeline — Media Library, REST `/wp/v2/media`, post editor's
	 * media insert, etc. — not just uploads through this plugin's own
	 * portal. Required because once SVG MIME is allowed (whether by us, by
	 * the host, or by another plugin), an unsanitized upload path is a
	 * stored-XSS vector.
	 *
	 * @param array $file Upload data array (`tmp_name`, `name`, `type`, ...).
	 * @return array Same array; on parse failure `error` is set so WP rejects.
	 */
	public static function sanitize_uploaded_svg_filter( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return $file;
		}

		$ext = '';
		if ( ! empty( $file['name'] ) ) {
			$ext = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		}
		$type   = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';
		$is_svg = ( 'image/svg+xml' === $type ) || ( 'svg' === $ext ) || ( 'svgz' === $ext );

		if ( ! $is_svg ) {
			return $file;
		}

		// Compressed SVGs cannot be parsed as XML in place; refuse rather
		// than risk silently storing un-sanitized content.
		if ( 'svgz' === $ext ) {
			$file['error'] = __( 'Compressed SVG (.svgz) uploads are not allowed for security reasons.', 'axtolab-ai-connector' );
			return $file;
		}

		$result = self::sanitize_svg( $file['tmp_name'] );
		if ( is_wp_error( $result ) ) {
			$file['error'] = $result->get_error_message();
		}
		return $file;
	}

	/**
	 * Sanitize an SVG file by stripping dangerous elements and attributes.
	 *
	 * Removes script tags, event handlers (on*), xlink:href to data/javascript URIs,
	 * and other potentially dangerous SVG content.
	 *
	 * @param string $file_path Path to the SVG temp file.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function sanitize_svg( $file_path ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return new WP_Error( 'svg_fs_unavailable', 'Filesystem unavailable for SVG sanitization.', array( 'status' => 500 ) );
		}
		$contents = $wp_filesystem->get_contents( $file_path );
		if ( false === $contents || '' === trim( (string) $contents ) ) {
			return new WP_Error( 'svg_read_failed', 'Could not read SVG file.', array( 'status' => 400 ) );
		}

		libxml_use_internal_errors( true );

		$dom    = new DOMDocument();
		$loaded = $dom->loadXML( $contents, LIBXML_NONET );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return new WP_Error( 'svg_parse_failed', 'SVG file could not be parsed.', array( 'status' => 400 ) );
		}

		// Remove dangerous elements.
		$dangerous_tags = array( 'script', 'foreignObject', 'set', 'animate', 'animateTransform', 'animateMotion' );
		foreach ( $dangerous_tags as $tag ) {
			$elements = $dom->getElementsByTagName( $tag );
			// Iterate in reverse to safely remove.
			for ( $i = $elements->length - 1; $i >= 0; $i-- ) {
				$el = $elements->item( $i );
				$el->parentNode->removeChild( $el );
			}
		}

		// Remove dangerous attributes from all elements.
		$xpath = new DOMXPath( $dom );
		$all   = $xpath->query( '//*' );
		foreach ( $all as $node ) {
			$remove_attrs = array();
			foreach ( $node->attributes as $attr ) {
				$name  = strtolower( $attr->name );
				$value = strtolower( trim( $attr->value ) );

				// Remove all on* event handlers.
				if ( 0 === strpos( $name, 'on' ) ) {
					$remove_attrs[] = $attr->name;
					continue;
				}
				// Remove href/xlink:href pointing to javascript: or data:.
				if ( in_array( $name, array( 'href', 'xlink:href' ), true ) ) {
					if ( preg_match( '/^\s*(javascript|data)\s*:/i', $value ) ) {
						$remove_attrs[] = $attr->name;
					}
				}
			}
			foreach ( $remove_attrs as $attr_name ) {
				$node->removeAttribute( $attr_name );
			}
		}

		$clean = $dom->saveXML();
		if ( false === $clean ) {
			return new WP_Error( 'svg_save_failed', 'Failed to save sanitized SVG.', array( 'status' => 500 ) );
		}

		if ( ! $wp_filesystem->put_contents( $file_path, $clean, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'svg_write_failed', 'Failed to write sanitized SVG.', array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Build a simplified media record for upload responses.
	 *
	 * @param int    $media_id The attachment ID.
	 * @param string $filename The original filename.
	 * @return array { media_id, filename, url, thumbnail_url }
	 */
	private static function to_upload_record( $media_id, $filename ) {
		$thumbnail = wp_get_attachment_image_src( $media_id, 'medium' );

		return array(
			'media_id'      => $media_id,
			'filename'      => $filename,
			'url'           => wp_get_attachment_url( $media_id ),
			'thumbnail_url' => is_array( $thumbnail ) ? $thumbnail[0] : wp_get_attachment_url( $media_id ),
		);
	}
}

if ( ! class_exists( 'MCP_Gateway_Upload_Portal', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Upload_Portal', 'MCP_Gateway_Upload_Portal' );
}
