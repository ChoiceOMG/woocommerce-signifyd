<?php
/**
 * Case creation and order meta handling.
 *
 * Owns two responsibilities: deciding which orders get screened and when
 * (the hooks registered in init(), plus the eligibility check), and being the
 * single writer of Signifyd data onto an order (store_case_data(), called by
 * both the webhook receiver and the admin AJAX handlers).
 *
 * Every order read and write here goes through the WooCommerce CRUD API, so
 * the class behaves identically under legacy post storage and HPOS.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Case creation triggers, order eligibility, and Signifyd order meta.
 *
 * Static throughout: the class holds no state, and its meta key constants
 * are the canonical reference for every other class that reads Signifyd data
 * off an order.
 */
class WC_Signifyd_Orders {

	/**
	 * Signifyd case (investigation) id. Its presence marks an order as
	 * already screened, and it is the key find_by_case_id() matches on.
	 */
	const META_CASE_ID = '_signifyd_case_id';

	/** Full case payload from the last webhook or refresh, JSON-encoded. */
	const META_CASE_DATA = '_signifyd_case_data';

	/** Signifyd risk score, rounded to a whole number. */
	const META_SCORE = '_signifyd_score';

	/** Latest guarantee disposition (APPROVED, DECLINED, and similar). */
	const META_DISPOSITION = '_signifyd_guarantee_disposition';

	/** Site-local mysql timestamp of the last case-data write. */
	const META_UPDATED_AT = '_signifyd_updated_at';

	/** Topic of the most recent webhook that touched this order. */
	const META_TOPIC = '_signifyd_webhook_topic';

	/**
	 * In-flight marker set while a case is being created, cleared when the
	 * attempt finishes. Backs up the option-based lock in maybe_create_case()
	 * for the case where a previous request died mid-flight.
	 */
	const META_CREATING = '_signifyd_case_creating';

	/** Site-local mysql timestamp of the last manual guarantee request. */
	const META_GUARANTEE_AT = '_signifyd_guarantee_requested_at';

	/**
	 * Bind case creation to whichever order event the settings screen
	 * selects, plus an always-on thankyou-page fallback. maybe_create_case()
	 * is idempotent, so having more than one hook call it is safe.
	 */
	public static function init() {
		$create_on = WC_Signifyd_Settings::create_on();

		if ( $create_on === 'processing' ) {
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_create_case' ), 20 );
		} else {
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_create_case' ), 20 );
		}

		// Fallback: if the primary event never fired (unusual gateway flows),
		// still create the case when the customer reaches the order-received page.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'maybe_create_case' ), 20 );
	}

	/**
	 * Is this order in scope for Signifyd screening?
	 *
	 * Defaults to a payment-method check against the gateway list on the
	 * settings screen, since only credit-card orders carry the AVS, CVV, and
	 * card fields the case payload is built from.
	 *
	 * @param WC_Order $order Order to test.
	 * @return bool
	 */
	public static function is_eligible( WC_Order $order ) {
		$eligible = in_array( $order->get_payment_method(), WC_Signifyd_Settings::eligible_gateways(), true );

		/**
		 * Filter whether an order should be screened.
		 *
		 * @param bool     $eligible
		 * @param WC_Order $order
		 */
		return (bool) apply_filters( 'wc_signifyd_order_is_eligible', $eligible, $order );
	}

	/**
	 * Create a Signifyd case for an order. Idempotent and safe to call repeatedly.
	 *
	 * Idempotency matters because up to two hooks can fire for the same
	 * order: the configured creation event, and the woocommerce_thankyou
	 * fallback. Three guards make repeat calls harmless: an existing case id
	 * short-circuits immediately, a cross-request lock (see the comment at
	 * the lock itself) serialises concurrent attempts, and an in-flight meta
	 * marker catches a request that died before releasing the lock.
	 *
	 * Failures never throw. An API error is logged and returns null, leaving
	 * the order without a case rather than blocking checkout.
	 *
	 * @param int $order_id Order id, as passed by the WooCommerce hooks.
	 * @return string|int|null Investigation id when a case was created, null
	 *                         when skipped or failed.
	 */
	public static function maybe_create_case( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( $order->get_meta( self::META_CASE_ID ) ) {
			return null; // already screened
		}

		if ( ! self::is_eligible( $order ) ) {
			return null;
		}

		$api = WC_Signifyd::api();
		if ( ! $api->has_key() ) {
			WC_Signifyd_Logger::error( sprintf( 'Order %d: no API key configured, case not created.', $order->get_id() ) );
			return null;
		}

		// Guard against concurrent hooks submitting the same order twice.
		//
		// This uses add_option() rather than set_transient(). Transients are
		// backed entirely by the object cache when a persistent one is
		// configured (common on production WooCommerce stores), and
		// wp_cache_set() always reports success regardless of any existing
		// value, so set_transient() cannot act as a lock in that setup.
		// add_option() always goes through the options table's unique key on
		// option_name, so the atomic insert this lock depends on holds either
		// way.
		$lock_key    = 'wc_signifyd_lock_' . $order->get_id();
		$lock_window = 2 * MINUTE_IN_SECONDS;

		if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
			$locked_at = (int) get_option( $lock_key );
			if ( ( time() - $locked_at ) < $lock_window ) {
				return null; // another request is already creating this case
			}
			// The lock is older than the window: a previous attempt crashed
			// before it could release the lock. Reclaim it.
			update_option( $lock_key, time() );
		}

		if ( $order->get_meta( self::META_CREATING ) ) {
			return null;
		}
		$order->update_meta_data( self::META_CREATING, time() );
		$order->save();

		$investigation_id = null;

		try {
			$payload          = WC_Signifyd_Case_Builder::build( $order );
			$investigation_id = $api->create_case( $payload );

			// Re-read: the builder may have written the AVS mapping comment.
			$order = wc_get_order( $order->get_id() );

			if ( $investigation_id ) {
				$order->update_meta_data( self::META_CASE_ID, $investigation_id );
				$order->add_order_note(
					sprintf(
						/* translators: %s: Signifyd case id */
						__( 'Signifyd case %s created.', 'fraud-screening-with-signifyd' ),
						$investigation_id
					)
				);
				WC_Signifyd_Logger::info( sprintf( 'Order %d: case %s created.', $order->get_id(), $investigation_id ) );
			} else {
				WC_Signifyd_Logger::error(
					sprintf( 'Order %d: case creation failed. %s', $order->get_id(), $api->get_last_error() )
				);
			}
		} catch ( Exception $e ) {
			WC_Signifyd_Logger::error( sprintf( 'Order %d: case creation exception: %s', $order_id, $e->getMessage() ) );
		}

		$order->delete_meta_data( self::META_CREATING );
		$order->save();
		delete_option( $lock_key );

		return $investigation_id;
	}

	/**
	 * Find an order by its Signifyd case id. HPOS-aware.
	 *
	 * Uses wc_get_orders() rather than a meta query against the posts table,
	 * so WooCommerce resolves it against whichever order storage backend is
	 * active. This is the lookup every inbound webhook and every admin AJAX
	 * action depends on.
	 *
	 * On the meta query: WooCommerce exposes no indexed lookup for arbitrary
	 * order meta, so matching a case id means a meta_key/meta_value query.
	 * Cost is bounded by 'limit' => 1, and under HPOS it resolves against
	 * wc_orders_meta, which carries an index on meta_key. Callers are webhook
	 * deliveries and deliberate admin clicks rather than anything on the
	 * storefront path, so this is not in front of customer traffic. A store
	 * large enough for it to matter should add a covering index on
	 * wc_orders_meta (meta_key, meta_value).
	 *
	 * @param string|int $case_id Signifyd case (investigation) id.
	 * @return WC_Order|null Matching order, or null when this store has no
	 *                       record of the case.
	 */
	public static function find_by_case_id( $case_id ) {
		$orders = wc_get_orders(
			array(
				'limit' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- No indexed alternative exists for order meta lookup; see the note above.
				'meta_key' => self::META_CASE_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded by limit=1 and off the storefront path; see the note above.
				'meta_value' => (string) $case_id,
				'return'     => 'objects',
			)
		);

		if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
			return $orders[0];
		}

		return null;
	}

	/**
	 * Persist case data onto an order, adding a note when the disposition moves.
	 *
	 * The single writer of Signifyd case data, shared by the webhook receiver
	 * and the admin refresh action. Safe to call with the same payload twice:
	 * every write is an overwrite, and the order note is added only when the
	 * disposition actually differs from what is already stored, so replayed
	 * or duplicated webhooks do not produce duplicate notes.
	 *
	 * Empty fields are skipped rather than written as blanks, so a partial
	 * payload (a rescore carrying a score but no disposition, for instance)
	 * cannot erase data an earlier webhook established.
	 *
	 * @param WC_Order $order Order to write to.
	 * @param array    $case  Case data from the API or a webhook.
	 * @param string   $topic Webhook topic, when applicable.
	 */
	public static function store_case_data( WC_Order $order, array $case, $topic = '' ) {
		$previous    = $order->get_meta( self::META_DISPOSITION );
		$disposition = isset( $case['guaranteeDisposition'] ) ? $case['guaranteeDisposition'] : '';
		$score       = isset( $case['score'] ) ? round( (float) $case['score'] ) : '';

		$order->update_meta_data( self::META_CASE_DATA, wp_json_encode( $case ) );
		$order->update_meta_data( self::META_UPDATED_AT, current_time( 'mysql' ) );

		if ( $topic !== '' ) {
			$order->update_meta_data( self::META_TOPIC, $topic );
		}
		if ( $disposition !== '' ) {
			$order->update_meta_data( self::META_DISPOSITION, $disposition );
		}
		if ( $score !== '' ) {
			$order->update_meta_data( self::META_SCORE, $score );
		}

		if ( $disposition !== '' && $disposition !== $previous ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: disposition, 2: score */
					__( 'Signifyd: guarantee disposition %1$s (score %2$s).', 'fraud-screening-with-signifyd' ),
					$disposition,
					$score !== '' ? $score : __( 'n/a', 'fraud-screening-with-signifyd' )
				)
			);
		}

		$order->save();

		/**
		 * Fires after Signifyd case data is stored on an order.
		 *
		 * Use this to drive custom workflow, for example holding or cancelling
		 * orders on a DECLINED disposition. The plugin deliberately never
		 * changes order status on its own.
		 *
		 * @param WC_Order $order
		 * @param array    $case
		 * @param string   $topic
		 */
		do_action( 'wc_signifyd_case_updated', $order, $case, $topic );
	}
}
