<?php
/**
 * Webhook receiver for Signifyd decision events.
 *
 * Signifyd calls this endpoint when a case is created, rescored, reviewed, or
 * a guarantee completes. The store registers the URL (see
 * WC_Signifyd_Settings::webhook_url()) in the Signifyd console.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint that receives Signifyd decision webhooks.
 *
 * The route is publicly reachable by necessity; handle() performs the HMAC
 * check that authenticates each request.
 */
class WC_Signifyd_Webhook {

	/** REST namespace. Versioned separately from the plugin version. */
	const NAMESPACE_V1 = 'wc-signifyd/v1';

	/** Route path within the namespace. */
	const ROUTE = '/webhook';

	/**
	 * Register the REST route once the REST API initialises.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Register the inbound webhook route.
	 *
	 * permission_callback is intentionally __return_true. Signifyd cannot
	 * present a WordPress cookie, nonce, or application password, so the
	 * route has to be openly reachable; authentication is the HMAC signature
	 * check performed at the top of handle(). Removing that check would
	 * leave the endpoint unauthenticated.
	 */
	public static function register_route() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( __CLASS__, 'handle' ),
				// Authentication is the HMAC signature verified inside the handler.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Read a Signifyd header, tolerating both the modern and legacy spellings.
	 *
	 * Signifyd has sent these headers both with and without the X- prefix
	 * over time, so each name is tried bare first and then prefixed. Always
	 * returns a string, so callers can compare against '' without a type
	 * check.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @param string          $name    Header name, without the X- prefix.
	 * @return string Header value, or an empty string when absent.
	 */
	protected static function header( WP_REST_Request $request, $name ) {
		$value = $request->get_header( $name );
		if ( empty( $value ) ) {
			$value = $request->get_header( 'x-' . $name );
		}
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Handle an inbound webhook.
	 *
	 * Response codes are chosen around Signifyd's retry behavior:
	 *
	 * - 200 with a reachability message: unsigned probe (no signature or no
	 *   body). Nothing is read or written.
	 * - 403: signature verification failed. Logged, no order touched.
	 * - 200 "Test OK": verified cases/test webhook. Carries no real case id,
	 *   so it stops here.
	 * - 400: verified but unusable (unparseable JSON, or no case id present).
	 *   A retry would fail the same way, but the payload is malformed enough
	 *   to be worth flagging to the sender.
	 * - 200 "Case not found": verified, well-formed, but the case id matches
	 *   no order here. Deliberately 200 rather than 404 so Signifyd stops
	 *   retrying a case this store has no record of.
	 * - 200 "OK": processed, order updated.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body  = $request->get_body();
		$hash  = self::header( $request, 'signifyd-sec-hmac-sha256' );
		$topic = self::header( $request, 'signifyd-topic' );

		// Unsigned probe: confirm reachability without touching any data.
		if ( $hash === '' || $body === '' ) {
			return new WP_REST_Response(
				array( 'message' => 'You have successfully reached the webhook endpoint' ),
				200
			);
		}

		$api = WC_Signifyd::api();

		if ( ! $api->is_valid_webhook( $body, $hash, $topic ) ) {
			WC_Signifyd_Logger::warning(
				sprintf( 'Webhook rejected: invalid signature (topic %s).', $topic !== '' ? $topic : 'none' )
			);
			return new WP_REST_Response( array( 'message' => 'Invalid webhook signature' ), 403 );
		}

		if ( $topic === 'cases/test' ) {
			WC_Signifyd_Logger::info( 'Webhook test received and verified.' );
			return new WP_REST_Response( array( 'message' => 'Test OK' ), 200 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'message' => 'Malformed JSON body' ), 400 );
		}

		// V2 sends caseId; V3 sends signifydId.
		$case_id = '';
		if ( ! empty( $payload['caseId'] ) ) {
			$case_id = (string) $payload['caseId'];
		} elseif ( ! empty( $payload['signifydId'] ) ) {
			$case_id = (string) $payload['signifydId'];
		}

		if ( $case_id === '' ) {
			return new WP_REST_Response( array( 'message' => 'No case id in payload' ), 400 );
		}

		$order = WC_Signifyd_Orders::find_by_case_id( $case_id );

		if ( ! $order ) {
			// 200 so Signifyd stops retrying a case this store does not know about.
			WC_Signifyd_Logger::warning(
				sprintf( 'Webhook for unknown case %s (topic %s).', $case_id, $topic !== '' ? $topic : 'none' )
			);
			return new WP_REST_Response( array( 'message' => 'Case not found' ), 200 );
		}

		WC_Signifyd_Orders::store_case_data( $order, $payload, $topic );

		WC_Signifyd_Logger::info(
			sprintf(
				'Webhook processed: case %s, order %d, topic %s.',
				$case_id,
				$order->get_id(),
				$topic !== '' ? $topic : 'none'
			)
		);

		return new WP_REST_Response( array( 'message' => 'OK' ), 200 );
	}
}
