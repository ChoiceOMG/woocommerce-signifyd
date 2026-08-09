<?php
/**
 * Admin: order metabox and its actions.
 *
 * Renders the Signifyd panel on the order edit screen and serves the three
 * AJAX actions behind its buttons. Loaded only when is_admin() is true.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order-screen metabox and the AJAX actions behind its buttons.
 *
 * Every entry point that mutates anything routes through
 * validate_request() first.
 */
class WC_Signifyd_Admin {

	/**
	 * Nonce action shared by all three AJAX endpoints.
	 *
	 * One action covers all of them because they share one capability
	 * requirement and are rendered together in the same metabox. The value
	 * must match on both sides: wp_create_nonce() in render_buttons() and
	 * check_ajax_referer() in validate_request().
	 */
	const NONCE = 'wc_signifyd_actions';

	/**
	 * Register metabox, asset, and AJAX hooks.
	 *
	 * Each wp_ajax_* suffix here is also the data-action value on the
	 * matching button in render_buttons(); the two must stay in step.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_wc_signifyd_close_case', array( __CLASS__, 'ajax_close_case' ) );
		add_action( 'wp_ajax_wc_signifyd_purchase_guarantee', array( __CLASS__, 'ajax_purchase_guarantee' ) );
		add_action( 'wp_ajax_wc_signifyd_refresh_case', array( __CLASS__, 'ajax_refresh_case' ) );
	}

	/**
	 * Screen ids for the order edit screen, covering both HPOS and legacy storage.
	 *
	 * Legacy post storage uses the shop_order post type as the screen id;
	 * HPOS uses an admin page id that WooCommerce resolves through
	 * wc_get_page_screen_id(). Both are registered so the metabox appears
	 * whichever storage the store runs, and the same list gates asset
	 * loading in enqueue().
	 *
	 * @return string[] Unique, non-empty screen ids.
	 */
	protected static function order_screen_ids() {
		$ids = array( 'shop_order', 'woocommerce_page_wc-orders' );
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}
		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Register the Signifyd metabox on every order edit screen variant.
	 */
	public static function add_meta_box() {
		foreach ( self::order_screen_ids() as $screen_id ) {
			add_meta_box(
				'wc-signifyd-score',
				__( 'Signifyd Score', 'fraud-screening-for-woocommerce-with-signifyd' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen_id,
				'side',
				'high'
			);
		}
	}

	/**
	 * Resolve the order being edited from the metabox callback argument.
	 *
	 * WordPress passes a WP_Post on the legacy order screen and WooCommerce
	 * passes a WC_Order on the HPOS screen, so the callback has to accept
	 * either. Reading $post->ID here is safe (and HPOS-correct) because it
	 * is only used to look the order up through wc_get_order().
	 *
	 * @param WP_Post|WC_Order $post_or_order Metabox callback argument.
	 * @return WC_Order|null Order, or null if it could not be resolved.
	 */
	protected static function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}

	/**
	 * Render the metabox contents.
	 *
	 * Prefers case data already stored on the order by a webhook. Falls back
	 * to one live API fetch when nothing is stored yet, which is what makes
	 * the box useful in the window between case creation and the first
	 * webhook arriving; that fetch is then persisted so it happens once
	 * rather than on every page load.
	 *
	 * @param WP_Post|WC_Order $post_or_order Metabox callback argument.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$case_id = $order->get_meta( WC_Signifyd_Orders::META_CASE_ID );

		if ( ! $case_id ) {
			echo '<p>' . esc_html__( 'No case.', 'fraud-screening-for-woocommerce-with-signifyd' ) . '</p>';
			return;
		}

		// Prefer stored data pushed by webhooks; fall back to a live fetch the
		// first time, so the box still works before any webhook has arrived.
		$stored = json_decode( (string) $order->get_meta( WC_Signifyd_Orders::META_CASE_DATA ), true );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			$case = WC_Signifyd::api()->get_case( $case_id );
			if ( ! is_array( $case ) ) {
				printf(
					'<p>%s</p><p><code>%s</code></p>',
					esc_html__( 'Signifyd API unavailable.', 'fraud-screening-for-woocommerce-with-signifyd' ),
					esc_html( WC_Signifyd::api()->get_last_error() )
				);
				self::render_buttons( $order, $case_id );
				return;
			}
			WC_Signifyd_Orders::store_case_data( $order, $case );
			$stored = $case;
		}

		$disposition     = isset( $stored['guaranteeDisposition'] ) ? $stored['guaranteeDisposition'] : __( 'NO GUARANTEE', 'fraud-screening-for-woocommerce-with-signifyd' );
		$eligible        = ! empty( $stored['guaranteeEligible'] );
		$team            = isset( $stored['associatedTeam']['teamName'] ) ? $stored['associatedTeam']['teamName'] : '';
		$adjusted        = isset( $stored['adjustedScore'] ) ? $stored['adjustedScore'] : '';
		$score           = isset( $stored['score'] ) ? round( (float) $stored['score'] ) : '';
		$status          = isset( $stored['status'] ) ? $stored['status'] : '';
		$investigation   = isset( $stored['investigationId'] ) ? $stored['investigationId'] : $case_id;
		$avs_comment     = $order->get_meta( '_signifyd_avs_mapping_comment' );
		$updated_at      = $order->get_meta( WC_Signifyd_Orders::META_UPDATED_AT );

		echo '<div class="wc-signifyd-box">';

		echo '<p class="wc-signifyd-score-line"><strong>' . esc_html__( 'Score', 'fraud-screening-for-woocommerce-with-signifyd' ) . ':</strong> ';
		echo '<span class="wc-signifyd-score">' . esc_html( $score ) . '</span></p>';

		echo '<p class="wc-signifyd-details">';
		printf( '%s: %s<br/>', esc_html__( 'Disposition', 'fraud-screening-for-woocommerce-with-signifyd' ), esc_html( $disposition ) );
		printf(
			'%s: %s<br/>',
			esc_html__( 'Guarantee eligible', 'fraud-screening-for-woocommerce-with-signifyd' ),
			$eligible ? esc_html__( 'TRUE', 'fraud-screening-for-woocommerce-with-signifyd' ) : esc_html__( 'FALSE', 'fraud-screening-for-woocommerce-with-signifyd' )
		);
		if ( $team !== '' ) {
			printf( '%s: %s<br/>', esc_html__( 'Associated team', 'fraud-screening-for-woocommerce-with-signifyd' ), esc_html( $team ) );
		}
		if ( $adjusted !== '' ) {
			printf( '%s: %s<br/>', esc_html__( 'Adjusted score', 'fraud-screening-for-woocommerce-with-signifyd' ), esc_html( $adjusted ) );
		}
		if ( $status !== '' ) {
			printf( '%s: %s<br/>', esc_html__( 'Status', 'fraud-screening-for-woocommerce-with-signifyd' ), esc_html( $status ) );
		}
		printf( '%s: %s<br/>', esc_html__( 'Case ID', 'fraud-screening-for-woocommerce-with-signifyd' ), esc_html( $investigation ) );
		if ( $avs_comment ) {
			echo esc_html( $avs_comment ) . '<br/>';
		}
		if ( $updated_at ) {
			printf( '<em>%s: %s</em>', esc_html__( 'Updated', 'fraud-screening-for-woocommerce-with-signifyd' ), esc_html( $updated_at ) );
		}
		echo '</p>';

		self::render_buttons( $order, $investigation );

		echo '</div>';
	}

	/**
	 * Render the four action controls.
	 *
	 * View Case is a plain external link to the Signifyd console. The other
	 * three are buttons carrying their AJAX action, case id, and a fresh
	 * nonce as data attributes for assets/js/admin.js to read. Each
	 * data-action value must match a wp_ajax_* hook registered in init().
	 *
	 * The two billable or destructive actions also carry a data-confirm
	 * string, which the JS turns into a confirmation prompt.
	 *
	 * @param WC_Order   $order   Order being displayed.
	 * @param string|int $case_id Signifyd case (investigation) id.
	 */
	protected static function render_buttons( WC_Order $order, $case_id ) {
		$nonce = wp_create_nonce( self::NONCE );
		?>
		<p class="wc-signifyd-actions">
			<a href="<?php echo esc_url( 'https://app.signifyd.com/cases/' . rawurlencode( (string) $case_id ) ); ?>"
			   class="button" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'View Case', 'fraud-screening-for-woocommerce-with-signifyd' ); ?>
			</a>
			<button type="button" class="button wc-signifyd-action"
			        data-action="wc_signifyd_refresh_case"
			        data-caseid="<?php echo esc_attr( $case_id ); ?>"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Refresh', 'fraud-screening-for-woocommerce-with-signifyd' ); ?>
			</button>
			<button type="button" class="button wc-signifyd-action"
			        data-action="wc_signifyd_close_case"
			        data-confirm="<?php echo esc_attr__( 'Close this Signifyd case?', 'fraud-screening-for-woocommerce-with-signifyd' ); ?>"
			        data-caseid="<?php echo esc_attr( $case_id ); ?>"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Close Case', 'fraud-screening-for-woocommerce-with-signifyd' ); ?>
			</button>
			<button type="button" class="button button-primary wc-signifyd-action"
			        data-action="wc_signifyd_purchase_guarantee"
			        data-confirm="<?php echo esc_attr__( 'Submit this case for a Signifyd guarantee? This is billable on your Signifyd account.', 'fraud-screening-for-woocommerce-with-signifyd' ); ?>"
			        data-caseid="<?php echo esc_attr( $case_id ); ?>"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Purchase Guarantee', 'fraud-screening-for-woocommerce-with-signifyd' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Enqueue the metabox script and stylesheet on order screens only.
	 *
	 * Gated on the same screen-id list the metabox itself registers against,
	 * so no other admin page pays for these assets.
	 *
	 * @param string $hook Current admin page hook suffix, unused; the screen
	 *                     id is the reliable discriminator across both the
	 *                     legacy and HPOS order screens.
	 */
	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, self::order_screen_ids(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-signifyd-admin',
			WC_SIGNIFYD_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WC_SIGNIFYD_VERSION,
			true
		);

		wp_enqueue_style(
			'wc-signifyd-admin',
			WC_SIGNIFYD_URL . 'assets/css/admin.css',
			array(),
			WC_SIGNIFYD_VERSION
		);
	}

	/**
	 * Shared guard for the AJAX endpoints.
	 *
	 * Checks the shared nonce, then the edit_shop_orders capability, then
	 * that a numeric case id was supplied. Every AJAX handler calls this
	 * first and before touching any input.
	 *
	 * Does not return on failure: check_ajax_referer() and
	 * wp_send_json_error() both terminate the request internally, so a
	 * handler reaching the line after this call has already passed all
	 * three checks and can treat the return value as trusted.
	 *
	 * The ctype_digit() test is what lets callers interpolate the id into an
	 * API path without further escaping.
	 *
	 * @return string Validated, digits-only case id.
	 */
	protected static function validate_request() {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'fraud-screening-for-woocommerce-with-signifyd' ) ), 403 );
		}

		$case_id = isset( $_POST['caseid'] ) ? sanitize_text_field( wp_unslash( $_POST['caseid'] ) ) : '';

		if ( $case_id === '' || ! ctype_digit( $case_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing or invalid case id.', 'fraud-screening-for-woocommerce-with-signifyd' ) ), 400 );
		}

		return $case_id;
	}

	/**
	 * Handles wp_ajax_wc_signifyd_refresh_case (the "Refresh" button).
	 *
	 * Guarded by validate_request(): requires the wc_signifyd_actions nonce
	 * and the edit_shop_orders capability.
	 */
	public static function ajax_refresh_case() {
		$case_id = self::validate_request();

		$case = WC_Signifyd::api()->get_case( $case_id );
		if ( ! is_array( $case ) ) {
			wp_send_json_error(
				array( 'message' => WC_Signifyd::api()->get_last_error() ),
				502
			);
		}

		$order = WC_Signifyd_Orders::find_by_case_id( $case_id );
		if ( $order ) {
			WC_Signifyd_Orders::store_case_data( $order, $case );
		}

		wp_send_json_success( array( 'message' => __( 'Case refreshed.', 'fraud-screening-for-woocommerce-with-signifyd' ) ) );
	}

	/**
	 * Handles wp_ajax_wc_signifyd_close_case (the "Close Case" button).
	 *
	 * Guarded by validate_request(): requires the wc_signifyd_actions nonce
	 * and the edit_shop_orders capability.
	 */
	public static function ajax_close_case() {
		$case_id = self::validate_request();

		$result = WC_Signifyd::api()->close_case( $case_id );
		if ( ! is_array( $result ) ) {
			wp_send_json_error( array( 'message' => WC_Signifyd::api()->get_last_error() ), 502 );
		}

		$order = WC_Signifyd_Orders::find_by_case_id( $case_id );
		if ( $order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: case id, 2: username */
					__( 'Signifyd case %1$s closed by %2$s.', 'fraud-screening-for-woocommerce-with-signifyd' ),
					$case_id,
					wp_get_current_user()->user_login
				)
			);
			$order->save();
		}

		WC_Signifyd_Logger::info(
			sprintf( 'Case %s closed by %s.', $case_id, wp_get_current_user()->user_login )
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: case id */
					__( 'Case %s closed.', 'fraud-screening-for-woocommerce-with-signifyd' ),
					$case_id
				),
			)
		);
	}

	/**
	 * Handles wp_ajax_wc_signifyd_purchase_guarantee (the "Purchase Guarantee"
	 * button).
	 *
	 * Guarded by validate_request(): requires the wc_signifyd_actions nonce
	 * and the edit_shop_orders capability. Billable on the Signifyd account.
	 */
	public static function ajax_purchase_guarantee() {
		$case_id = self::validate_request();

		$disposition = WC_Signifyd::api()->create_guarantee( $case_id );

		if ( $disposition === null ) {
			WC_Signifyd_Logger::error(
				sprintf( 'Guarantee request failed for case %s: %s', $case_id, WC_Signifyd::api()->get_last_error() )
			);
			wp_send_json_error( array( 'message' => WC_Signifyd::api()->get_last_error() ), 502 );
		}

		$order = WC_Signifyd_Orders::find_by_case_id( $case_id );
		if ( $order ) {
			$order->update_meta_data( WC_Signifyd_Orders::META_DISPOSITION, $disposition );
			$order->update_meta_data( WC_Signifyd_Orders::META_GUARANTEE_AT, current_time( 'mysql' ) );
			$order->add_order_note(
				sprintf(
					/* translators: 1: username, 2: disposition */
					__( 'Signifyd guarantee requested by %1$s: %2$s.', 'fraud-screening-for-woocommerce-with-signifyd' ),
					wp_get_current_user()->user_login,
					$disposition
				)
			);
			$order->save();
		}

		WC_Signifyd_Logger::info(
			sprintf(
				'Guarantee requested for case %s by %s: %s.',
				$case_id,
				wp_get_current_user()->user_login,
				$disposition
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: guarantee disposition */
					__( 'Guarantee submitted. Disposition: %s', 'fraud-screening-for-woocommerce-with-signifyd' ),
					$disposition
				),
			)
		);
	}
}
