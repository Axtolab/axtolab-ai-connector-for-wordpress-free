/* global jQuery, axtolabAiConnector */
/**
 * Axtolab AI Connector — connection wizard
 *
 * Drives the inline "+ Add new connection" panel in the Connections section
 * of the AI Connector admin page. Handles:
 *
 *   - Open / Close panel
 *   - Step 1 "Admin / Dedicated" radio toggle (shows the username field for
 *     the dedicated path)
 *   - Step 3 Verify button (loopback Basic-auth call to /wp/v2/users/me)
 *   - Step 4 capability preset → checkbox sync
 *   - Footer "Create connection" → AJAX create handler + token display
 */
(function ($) {
	'use strict';

	var cfg = window.axtolabAiConnector || {};

	if ( !cfg.actions || !cfg.actions.wizardVerify ) {
		// Localized data missing — bail silently. The wizard markup will still
		// render but be inert; the user just sees the panel without behavior.
		return;
	}

	var $panel       = $( '#mcp-wizard-panel' );
	var $openBtn     = $( '#mcp-wizard-open-btn' );
	var $cancelBtn   = $( '#mcp-wizard-cancel-btn' );
	var $verifyBtn   = $( '#mcp-wizard-verify-btn' );
	var $createBtn   = $( '#mcp-wizard-create-btn' );
	var $verifyMsg   = $( '#mcp-wizard-verify-result' );
	var $createMsg   = $( '#mcp-wizard-create-message' );
	var $success     = $( '#mcp-wizard-success' );
	var $configureBtn = $( '#mcp-wizard-configure-btn' );
	var $doneBtn     = $( '#mcp-wizard-done-btn' );
	var $appPwdField = $( '#mcp-wizard-app-password' );
	var $userField   = $( '#mcp-wizard-username' );
	var $labelField  = $( '#mcp-wizard-label' );
	var $clientField = $( '#mcp-wizard-client-type' );
	var $presetField = $( '#mcp-wizard-preset' );
	var $capRow      = $( '#mcp-wizard-caps' );
	var $pathRadios  = $panel.find( 'input[name="mcp-wizard-path"]' );
	var $usernameRow = $panel.find( '.axtolab-wizard-field[data-path="dedicated"]' );

	if ( $panel.length === 0 ) {
		return;
	}

	// Preset definitions mirror the per-connection preset map. Keep this in
	// sync with the inline-script connCapPresets table in the admin page.
	var presets = {
		full_access:     [ 'read', 'create_edit', 'publish', 'trash_restore', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal' ],
		standard:        [ 'read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal' ],
		draft_only:      [ 'read', 'create_edit', 'media_manage', 'taxonomy', 'seo', 'image', 'upload_portal' ],
		read_only:       [ 'read' ],
		content_manager: [ 'read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo' ],
		media_manager:   [ 'read', 'media_manage' ],
		seo_specialist:  [ 'read', 'seo' ]
	};

	function showPanel() {
		$panel.prop( 'hidden', false );
		$openBtn.attr( 'aria-expanded', 'true' );
		// Reset any stale UI state.
		$success.prop( 'hidden', true );
		$createMsg.text( '' ).removeClass( 'is-success is-error' );
		$verifyMsg.text( '' ).removeClass( 'is-success is-error' );
		$createBtn.prop( 'disabled', true );
		setTimeout( function () { $labelField.trigger( 'focus' ); }, 50 );
	}

	function hidePanel() {
		$panel.prop( 'hidden', true );
		$openBtn.attr( 'aria-expanded', 'false' );
	}

	function resetWizard() {
		$labelField.val( '' );
		$clientField.val( 'claude_desktop' );
		$appPwdField.val( '' );
		$userField.val( '' );
		$presetField.val( 'standard' );
		applyPreset( 'standard' );
		$success.prop( 'hidden', true );
		$configureBtn.attr( 'data-connection-id', '' ).data( 'connection-id', '' );
		$createBtn.prop( 'disabled', true );
		$verifyMsg.text( '' ).removeClass( 'is-success is-error' );
		$createMsg.text( '' ).removeClass( 'is-success is-error' );
		// Reset path back to "admin".
		$pathRadios.filter( '[value="admin"]' ).prop( 'checked', true ).trigger( 'change' );
	}

	function applyPreset( name ) {
		if ( name === 'custom' ) {
			return;
		}
		var caps = presets[ name ] || [];
		$capRow.find( '.mcp-wizard-cap-checkbox' ).each( function () {
			var $cb = $( this );
			if ( $cb.data( 'cap' ) === 'read' ) {
				return; // 'read' is always on.
			}
			$cb.prop( 'checked', caps.indexOf( $cb.data( 'cap' ) ) !== -1 );
		} );
	}

	function getCheckedCaps() {
		var caps = [];
		$capRow.find( '.mcp-wizard-cap-checkbox:checked' ).each( function () {
			caps.push( $( this ).data( 'cap' ) );
		} );
		return caps;
	}

	function ajax( action, extra, onSuccess, onError ) {
		var data = $.extend( { action: action, nonce: cfg.ajaxNonce }, extra );
		$.post( cfg.ajaxUrl, data )
			.done( function ( res ) {
				if ( res && res.success ) {
					if ( onSuccess ) { onSuccess( res.data || {} ); }
				} else {
					var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Request failed.';
					if ( onError ) { onError( msg ); }
				}
			} )
			.fail( function ( jqXHR ) {
				// Surface server-side error messages on HTTP 4xx/5xx
				// (wp_send_json_error returns JSON with .data.message even on
				// non-2xx — but jQuery routes those through .fail not .done).
				var msg = 'Network error. Please try again.';
				if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
					msg = jqXHR.responseJSON.data.message;
				}
				if ( onError ) { onError( msg ); }
			} );
	}

	// ── Toggle panel ─────────────────────────────────────────────────────
	$openBtn.on( 'click', function () {
		if ( $panel.prop( 'hidden' ) ) {
			showPanel();
		} else {
			hidePanel();
		}
	} );

	$cancelBtn.on( 'click', function () {
		resetWizard();
		hidePanel();
	} );

	$doneBtn.on( 'click', function () {
		// Reload to show the new row in the connections table.
		window.location.reload();
	} );

	$configureBtn.on( 'click', function () {
		if ( typeof window.axtolabAiConnectorOpenConnectionManager === 'function' ) {
			window.axtolabAiConnectorOpenConnectionManager( $( this ).attr( 'data-connection-id' ) || $( this ).data( 'connection-id' ) || '' );
			return;
		}
		window.location.reload();
	} );

	// ── Path radio toggle ────────────────────────────────────────────────
	$pathRadios.on( 'change', function () {
		var path = $pathRadios.filter( ':checked' ).val();
		if ( path === 'dedicated' ) {
			$usernameRow.prop( 'hidden', false );
		} else {
			$usernameRow.prop( 'hidden', true );
			$userField.val( '' );
		}
	} );

	// ── Preset → checkbox sync ───────────────────────────────────────────
	$presetField.on( 'change', function () {
		applyPreset( $( this ).val() );
	} );

	// ── Verify button ────────────────────────────────────────────────────
	$verifyBtn.on( 'click', function () {
		var pwd = $.trim( $appPwdField.val() );
		if ( !pwd ) {
			$verifyMsg.text( 'Paste the Application Password first.' ).removeClass( 'is-success' ).addClass( 'is-error' );
			return;
		}
		$verifyBtn.prop( 'disabled', true ).text( cfg.strings.verifying || 'Verifying…' );
		$verifyMsg.text( '' ).removeClass( 'is-success is-error' );

		ajax(
			cfg.actions.wizardVerify,
			{
				app_password: pwd,
				username:     $.trim( $userField.val() )
			},
			function ( data ) {
				$verifyBtn.prop( 'disabled', false ).text( 'Verify' );
				var roles = ( data.user_roles || [] ).join( ', ' );
				var msg   = '✓ Authenticated as ' + ( data.user_display || data.user_login );
				if ( roles ) { msg += ' (' + roles + ')'; }
				$verifyMsg.text( msg ).removeClass( 'is-error' ).addClass( 'is-success' );

				if ( data.warning ) {
					$verifyMsg.append( ' — ' + data.warning );
				}

				if ( data.collision ) {
					var note = 'This Application Password is already used by connection "' +
						( data.collision.label || data.collision.connection_id ) +
						'". For best audit-log clarity, create a separate App Password for each client.';
					if ( !window.confirm( note + '\n\nUse it anyway?' ) ) {
						$createBtn.prop( 'disabled', true );
						return;
					}
				}

				$createBtn.prop( 'disabled', false ).trigger( 'focus' );
			},
			function ( errMsg ) {
				$verifyBtn.prop( 'disabled', false ).text( 'Verify' );
				$verifyMsg.text( errMsg ).removeClass( 'is-success' ).addClass( 'is-error' );
				$createBtn.prop( 'disabled', true );
			}
		);
	} );

	// Any change to the credentials means we need a fresh Verify pass.
	$appPwdField.on( 'input', function () {
		$createBtn.prop( 'disabled', true );
		$verifyMsg.text( '' ).removeClass( 'is-success is-error' );
	} );
	$userField.on( 'input', function () {
		$createBtn.prop( 'disabled', true );
		$verifyMsg.text( '' ).removeClass( 'is-success is-error' );
	} );

	// ── Create button ────────────────────────────────────────────────────
	$createBtn.on( 'click', function () {
		var pwd = $.trim( $appPwdField.val() );
		if ( !pwd ) {
			return;
		}
		$createBtn.prop( 'disabled', true ).text( cfg.strings.creating || 'Creating…' );
		$createMsg.text( '' ).removeClass( 'is-success is-error' );

		ajax(
			cfg.actions.wizardCreate,
			{
				app_password: pwd,
				username:     $.trim( $userField.val() ),
				label:        $.trim( $labelField.val() ),
				client_type:  $clientField.val(),
				capabilities: getCheckedCaps()
			},
			function ( data ) {
				$createBtn.text( 'Create connection' );
				$createMsg.text( data.message || 'Connection created.' ).removeClass( 'is-error' ).addClass( 'is-success' );
				$( '#mcp-wizard-token' ).text( data.token || '' );
				$configureBtn.attr( 'data-connection-id', data.connection_id || '' ).data( 'connection-id', data.connection_id || '' );
				$success.prop( 'hidden', false );
				// Wipe the password field so it doesn't linger in the DOM.
				$appPwdField.val( '' );
			},
			function ( errMsg ) {
				$createBtn.prop( 'disabled', false ).text( 'Create connection' );
				$createMsg.text( errMsg ).removeClass( 'is-success' ).addClass( 'is-error' );
			}
		);
	} );

	// Ensure initial state matches the default "admin" path.
	$pathRadios.filter( ':checked' ).trigger( 'change' );
	applyPreset( $presetField.val() );

}( jQuery ));
