/**
 * Signifyd order metabox actions.
 *
 * Drives the three AJAX buttons rendered by
 * WC_Signifyd_Admin::render_buttons(): Refresh, Close Case, and Purchase
 * Guarantee. The View Case control is a plain link and is not handled here.
 *
 * Every button carries its own action name, case id, and nonce as data
 * attributes, so this file holds no endpoint names and needs no change when
 * an action is added or renamed on the PHP side.
 *
 * @package WC_Signifyd
 */
( function ( $ ) {
	'use strict';

	/**
	 * Delegated click handler for every metabox action button.
	 *
	 * Bound on document rather than on the buttons themselves so it survives
	 * WooCommerce re-rendering the metabox.
	 *
	 * Disables the whole button group for the duration of the request, since
	 * these actions are billable or destructive and double submission is the
	 * failure worth preventing. A successful action reloads the page so the
	 * metabox redraws from the freshly stored order meta; a failure
	 * re-enables the buttons in place so the operator can retry.
	 */
	$( document ).on( 'click', '.wc-signifyd-action', function ( e ) {
		e.preventDefault();

		var $button  = $( this );
		var confirmText = $button.data( 'confirm' );

		// Buttons with a data-confirm string are the billable or destructive
		// ones. Buttons without it (Refresh) act immediately.
		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}

		var $group = $button.closest( '.wc-signifyd-actions' );
		$group.find( '.wc-signifyd-action' ).prop( 'disabled', true );
		$button.addClass( 'wc-signifyd-busy' );

		// _ajax_nonce is the field name check_ajax_referer() reads by
		// default, which is what the PHP guard expects.
		$.post( window.ajaxurl, {
			action: $button.data( 'action' ),
			caseid: $button.data( 'caseid' ),
			_ajax_nonce: $button.data( 'nonce' )
		} )
			.done( function ( response ) {
				var message = ( response && response.data && response.data.message )
					? response.data.message
					: 'Done.';
				window.alert( message );
				window.location.reload();
			} )
			.fail( function ( xhr ) {
				// wp_send_json_error() sends its payload with a non-2xx
				// status, so the useful message arrives here rather than in
				// done(). Fall back to the bare status when the response is
				// not the expected JSON shape.
				var message = 'Request failed.';
				if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					message = xhr.responseJSON.data.message;
				} else if ( xhr.status ) {
					message = 'Request failed (HTTP ' + xhr.status + ').';
				}
				window.alert( message );
				$group.find( '.wc-signifyd-action' ).prop( 'disabled', false );
				$button.removeClass( 'wc-signifyd-busy' );
			} );
	} );
} )( jQuery );
