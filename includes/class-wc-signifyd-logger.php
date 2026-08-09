<?php
/**
 * Logging wrapper.
 *
 * Routes everything through WooCommerce's own logger so entries appear under
 * WooCommerce > Status > Logs and obey WooCommerce's retention settings. The
 * plugin never writes its own log files, and never logs request or response
 * bodies, which carry customer personal information.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logging facade over the WooCommerce logger.
 *
 * Read the note on log() before adding a call. It defines a hard constraint
 * on what a message may contain.
 */
class WC_Signifyd_Logger {

	/**
	 * Log source, which is how entries are filtered in the WooCommerce log
	 * viewer and what names the rotating log files WooCommerce writes.
	 */
	const SOURCE = 'signifyd';

	/**
	 * Write one entry through the WooCommerce logger.
	 *
	 * Silently does nothing when WooCommerce is unavailable, so a logging
	 * call can never be the thing that fatals a request.
	 *
	 * Messages must carry identifiers and short error text only: order ids,
	 * case ids, HTTP status codes, exception messages. Never pass a request
	 * or response body, or any card, AVS, or CVV value; entries here persist
	 * to disk and are readable by any admin.
	 *
	 * @param string $level   WooCommerce log level.
	 * @param string $message Message to record.
	 */
	protected static function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Record a routine event (case created, webhook processed).
	 *
	 * @param string $message Message to record.
	 */
	public static function info( $message ) {
		self::log( 'info', $message );
	}

	/**
	 * Record a suspicious but handled event (rejected signature, unknown case).
	 *
	 * @param string $message Message to record.
	 */
	public static function warning( $message ) {
		self::log( 'warning', $message );
	}

	/**
	 * Record a failure (API error, exception, missing configuration).
	 *
	 * @param string $message Message to record.
	 */
	public static function error( $message ) {
		self::log( 'error', $message );
	}
}
