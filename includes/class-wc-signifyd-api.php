<?php
/**
 * Minimal Signifyd API client built on the WordPress HTTP API.
 *
 * Implements only the calls this plugin needs. Deliberately dependency-free:
 * no bundled SDK, no cURL handling, no file logging. Everything routes through
 * wp_remote_* so it inherits the site's HTTP filters, proxy config and timeouts.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signifyd V2 Cases API client.
 *
 * Obtain the shared instance through WC_Signifyd::api() rather than
 * constructing one, so the API key is resolved once per request.
 */
class WC_Signifyd_API {

	/**
	 * Signifyd V2 Cases API base, with a trailing slash.
	 *
	 * V2 is deliberate. Signifyd's V3 Decisions API exists and is where new
	 * capability lands, but porting to it is out of scope; see README.md.
	 */
	const API_BASE = 'https://api.signifyd.com/v2/';

	/**
	 * Signifyd team API key, used as the HTTP Basic username and as the
	 * HMAC key for webhook signature verification.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Human-readable description of the most recent failure.
	 *
	 * Reset at the start of every request(). Callers read this after a null
	 * return to show the operator what went wrong. It may contain a
	 * truncated API error body, so it is surfaced to the acting admin only
	 * and never written to the log.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * @param string $api_key Signifyd team API key. An empty string is valid
	 *                        and puts the client into the has_key() === false
	 *                        state, where every request fails fast.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Description of the most recent failure, or an empty string.
	 *
	 * Only meaningful immediately after a call that returned null.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Whether this client has an API key to authenticate with.
	 *
	 * @return bool
	 */
	public function has_key() {
		return $this->api_key !== '';
	}

	/**
	 * Perform a request against the Signifyd API.
	 *
	 * The single choke point every public method goes through. Failures of
	 * all kinds (no key, transport error, non-2xx status, unparseable body)
	 * collapse to a null return with $last_error set, so callers only ever
	 * branch on null and never handle WP_Error or status codes themselves.
	 *
	 * A 2xx with an empty body returns an empty array, which is truthy for
	 * is_array() checks; that distinguishes "succeeded, nothing to say" from
	 * the null failure case.
	 *
	 * @param string     $endpoint Endpoint relative to the API base, with or
	 *                             without a leading slash.
	 * @param string     $method   HTTP method.
	 * @param array|null $body     Payload, JSON-encoded when present.
	 *
	 * @return array|null Decoded response, or null on failure.
	 */
	protected function request( $endpoint, $method = 'GET', $body = null ) {
		$this->last_error = '';

		if ( ! $this->has_key() ) {
			$this->last_error = __( 'No Signifyd API key configured.', 'fraud-screening-for-woocommerce-with-signifyd' );
			return null;
		}

		$args = array(
			'method'  => $method,
			'timeout' => (int) apply_filters( 'wc_signifyd_request_timeout', 30 ),
			'headers' => array(
				// Signifyd uses HTTP Basic auth: API key as username, empty password.
				'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url      = self::API_BASE . ltrim( $endpoint, '/' );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			WC_Signifyd_Logger::error( sprintf( 'API %s %s failed: %s', $method, $endpoint, $this->last_error ) );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			// Signifyd returns JSON error bodies; surface them verbatim, trimmed,
			// to the immediate caller only. The persisted log line stays limited
			// to the status code so response bodies never reach stored logs.
			$this->last_error = sprintf( 'HTTP %d: %s', $code, mb_substr( wp_strip_all_tags( (string) $raw ), 0, 300 ) );
			WC_Signifyd_Logger::error( sprintf( 'API %s %s failed with HTTP %d.', $method, $endpoint, $code ) );
			return null;
		}

		if ( $raw === '' ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$this->last_error = __( 'Malformed JSON in Signifyd response.', 'fraud-screening-for-woocommerce-with-signifyd' );
			WC_Signifyd_Logger::error( $this->last_error );
			return null;
		}

		return $decoded;
	}

	/**
	 * Create a case. Returns the investigation id, or null on failure.
	 *
	 * The returned investigation id is what Signifyd calls the case id
	 * everywhere else in its API and console, and is what this plugin stores
	 * on the order and matches inbound webhooks against.
	 *
	 * @param array $case Case payload, as built by WC_Signifyd_Case_Builder.
	 * @return int|string|null
	 */
	public function create_case( array $case ) {
		$response = $this->request( 'cases', 'POST', $case );
		if ( ! is_array( $response ) ) {
			return null;
		}
		return isset( $response['investigationId'] ) ? $response['investigationId'] : null;
	}

	/**
	 * Retrieve a case, including its current score and guarantee disposition.
	 *
	 * @param string|int $case_id Signifyd case (investigation) id.
	 * @return array|null Case data, or null on failure.
	 */
	public function get_case( $case_id ) {
		return $this->request( 'cases/' . rawurlencode( (string) $case_id ), 'GET' );
	}

	/**
	 * Close (dismiss) a case in Signifyd.
	 *
	 * Dismissing tells Signifyd the store is no longer pursuing the case. It
	 * has no effect on the WooCommerce order, which this plugin never
	 * transitions on its own.
	 *
	 * @param string|int $case_id Signifyd case (investigation) id.
	 * @return array|null Response body, or null on failure.
	 */
	public function close_case( $case_id ) {
		return $this->request(
			'cases/' . rawurlencode( (string) $case_id ),
			'PUT',
			array( 'status' => 'DISMISSED' )
		);
	}

	/**
	 * Submit a case for guarantee. Billable on the Signifyd account.
	 *
	 * Signifyd may answer synchronously with a final disposition, or
	 * acknowledge the submission and deliver the decision later by webhook.
	 * The async acknowledgement is normalised to the literal 'SUBMITTED' so
	 * callers always get a displayable string on success.
	 *
	 * @param string|int $case_id Signifyd case (investigation) id.
	 * @return string|null Guarantee disposition, 'SUBMITTED' when the
	 *                     decision is pending, or null on failure.
	 */
	public function create_guarantee( $case_id ) {
		$response = $this->request( 'guarantees', 'POST', array( 'caseId' => (int) $case_id ) );
		if ( ! is_array( $response ) ) {
			return null;
		}
		if ( isset( $response['disposition'] ) ) {
			return $response['disposition'];
		}
		// The async endpoint acknowledges with the caseId; the decision arrives by webhook.
		return isset( $response['caseId'] ) ? 'SUBMITTED' : null;
	}

	/**
	 * Cancel a guarantee. Signifyd credits the account for cancelled guarantees.
	 *
	 * Provided for completeness of the guarantee lifecycle and for use by
	 * store-specific code; no UI in this plugin currently calls it, since
	 * cancellation is usually driven from the Signifyd console.
	 *
	 * @param string|int $case_id Signifyd case (investigation) id.
	 * @return string|null Disposition, or null on failure.
	 */
	public function cancel_guarantee( $case_id ) {
		$response = $this->request(
			'cases/' . rawurlencode( (string) $case_id ) . '/guarantee',
			'PUT',
			array( 'guaranteeDisposition' => 'CANCELED' )
		);
		if ( ! is_array( $response ) ) {
			return null;
		}
		return isset( $response['disposition'] ) ? $response['disposition'] : null;
	}

	/**
	 * Verify a webhook signature.
	 *
	 * Signifyd sends X-SIGNIFYD-SEC-HMAC-SHA256: the base64-encoded HMAC-SHA256
	 * of the raw request body, keyed with the team API key. Console-initiated
	 * test webhooks are signed with the literal key "ABCDE" instead.
	 *
	 * The body must be the raw bytes as received. Any re-encoding, including
	 * a json_decode()/wp_json_encode() round trip, changes the hash and makes
	 * a legitimate webhook fail verification.
	 *
	 * Both comparisons use hash_equals() for timing safety. The "ABCDE"
	 * fallback is tried only for the cases/test topic, so a caller cannot use
	 * the publicly-known test key to forge a real case update.
	 *
	 * @param string $raw_body Raw, unmodified request body.
	 * @param string $hash     Signature header value.
	 * @param string $topic    Signifyd topic header.
	 *
	 * @return bool True when the signature matches.
	 */
	public function is_valid_webhook( $raw_body, $hash, $topic = '' ) {
		if ( ! is_string( $raw_body ) || ! is_string( $hash ) || $hash === '' ) {
			return false;
		}

		$expected = base64_encode( hash_hmac( 'sha256', $raw_body, $this->api_key, true ) );
		if ( hash_equals( $expected, $hash ) ) {
			return true;
		}

		if ( $topic === 'cases/test' ) {
			$test_expected = base64_encode( hash_hmac( 'sha256', $raw_body, 'ABCDE', true ) );
			if ( hash_equals( $test_expected, $hash ) ) {
				return true;
			}
		}

		return false;
	}
}
