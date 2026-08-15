<?php
/**
 * Builds the Signifyd case payload from a WooCommerce order.
 *
 * Gateway-specific fields (AVS, CVV, card BIN / last four / expiry) are read
 * through a filterable meta-key map so the plugin is not welded to one
 * payment gateway. Defaults target WooCommerce Moneris Payment Gateway.
 *
 * @package WC_Signifyd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a WC_Order into a Signifyd V2 case payload.
 *
 * The only class that reads gateway-specific order meta, and the place to
 * extend when adding support for another payment gateway.
 */
class WC_Signifyd_Case_Builder {

	/**
	 * Order meta keys holding gateway card / verification data.
	 *
	 * Keys are this plugin's stable field names; values are the order meta
	 * keys to read them from. Integrators remap the values for their own
	 * gateway through the wc_signifyd_gateway_meta_map filter rather than
	 * editing the defaults here.
	 *
	 * @return array Field name to order meta key.
	 */
	public static function meta_map() {
		return apply_filters(
			'wc_signifyd_gateway_meta_map',
			array(
				'avs'         => '_wc_moneris_avs',
				'cvv'         => '_wc_moneris_csc',
				'bin'         => '_wc_moneris_bin',
				'last_four'   => '_wc_moneris_account_four',
				'card_expiry' => '_wc_moneris_card_expiry_date',
			)
		);
	}

	/**
	 * Map a gateway AVS response code onto the Signifyd AVS vocabulary.
	 *
	 * Signifyd expects the standard single-character AVS codes. Moneris emits
	 * several codes outside that set, so they are translated here. Gateways
	 * that already emit standard codes pass through untouched.
	 *
	 * An empty code becomes 'U' (unavailable), which is what Signifyd expects
	 * for a gateway that returned no AVS result at all. An unrecognised code
	 * passes through unchanged on the assumption that it is already standard.
	 *
	 * The returned comment is stored on the order and shown in the metabox,
	 * so an operator can see how a code was translated.
	 *
	 * @param string $code Raw gateway AVS code.
	 * @return array [ mapped_code, human_readable_comment ]
	 */
	public static function map_avs( $code ) {
		$map = apply_filters(
			'wc_signifyd_avs_map',
			array(
				'D' => 'P',
				'E' => 'M',
				'F' => 'B',
				'K' => 'I',
				'L' => 'Z',
				'O' => 'A',
				'W' => 'M',
			)
		);

		$code = is_string( $code ) ? trim( $code ) : '';

		if ( $code === '' ) {
			return array( 'U', __( 'AVS mapped from empty to U', 'riskloom-fraud-screening-for-signifyd' ) );
		}

		if ( isset( $map[ $code ] ) ) {
			return array(
				$map[ $code ],
				sprintf(
					/* translators: 1: gateway AVS code, 2: Signifyd AVS code */
					__( 'AVS mapped from %1$s to %2$s', 'riskloom-fraud-screening-for-signifyd' ),
					$code,
					$map[ $code ]
				),
			);
		}

		return array( $code, __( 'AVS mapping not required', 'riskloom-fraud-screening-for-signifyd' ) );
	}

	/**
	 * Build the full case payload for an order.
	 *
	 * Assembles the four top-level sections the V2 Cases API expects:
	 * purchase (line items, totals, AVS/CVV results), recipient (who the
	 * goods go to), card (risk-signal fields and the billing address), and
	 * userAccount (the WordPress account behind the order, when there is
	 * one).
	 *
	 * Card fields here are risk signals only. The PAN and the CVV value
	 * itself never enter this payload; only the BIN, last four, expiry, and
	 * the gateway's AVS and CVV match results, all of which the gateway has
	 * already stored on the order.
	 *
	 * Writes one piece of meta as a side effect (the AVS mapping comment)
	 * and saves it immediately, since the caller re-reads the order.
	 *
	 * @param WC_Order $order Order to build a case for.
	 * @return array Case payload, after the wc_signifyd_case_payload filter.
	 */
	public static function build( WC_Order $order ) {
		$meta_map = self::meta_map();

		// Line items. Signifyd uses product identity and pricing to spot
		// patterns across cases, so each item carries its own url and image.
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$image      = wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'single-post-thumbnail' );

			$products[] = array(
				'itemId'        => (string) $product_id,
				'itemName'      => $item->get_name(),
				'itemUrl'       => get_permalink( $product_id ),
				'itemImage'     => $image ? $image[0] : null,
				'itemQuantity'  => (int) $item->get_quantity(),
				'itemPrice'     => wc_format_decimal( $item->get_subtotal(), wc_get_price_decimals() ),
				'itemIsDigital' => $item->get_product() ? $item->get_product()->is_virtual() : false,
			);
		}

		// One shipment covering the whole order. Carrier and tracking number
		// have no WooCommerce core equivalent, so they stay null unless a
		// shipping plugin supplies them through these filters.
		$shipments = array(
			array(
				'shipper'        => apply_filters( 'wc_signifyd_default_shipper', null, $order ),
				'shippingMethod' => $order->get_shipping_method() ? $order->get_shipping_method() : null,
				'shippingPrice'  => wc_format_decimal( $order->get_shipping_total(), wc_get_price_decimals() ),
				'trackingNumber' => apply_filters( 'wc_signifyd_tracking_number', null, $order ),
			),
		);

		list( $avs_code, $avs_comment ) = self::map_avs( $order->get_meta( $meta_map['avs'] ) );
		$order->update_meta_data( '_signifyd_avs_mapping_comment', $avs_comment );
		// Persist immediately: the caller re-reads the order by id right after
		// build() returns, which would otherwise silently lose this write.
		$order->save_meta_data();

		// Signifyd expects the alphabetic CVV response code only.
		$cvv_raw  = (string) $order->get_meta( $meta_map['cvv'] );
		$cvv_code = preg_replace( '/[^A-Za-z]/', '', $cvv_raw );

		$created_at = $order->get_date_created();

		$purchase = array(
			'browserIpAddress' => $order->get_customer_ip_address(),
			'orderSessionId'   => $order->get_order_key(),
			'orderId'          => (string) $order->get_id(),
			'createdAt'        => $created_at ? $created_at->format( DateTime::ATOM ) : null,
			'paymentGateway'   => $order->get_payment_method(),
			'paymentMethod'    => 'CREDIT_CARD',
			'transactionId'    => $order->get_transaction_id(),
			'currency'         => $order->get_currency(),
			'avsResponseCode'  => $avs_code,
			'cvvResponseCode'  => $cvv_code !== '' ? $cvv_code : null,
			'orderChannel'     => 'WEB',
			'totalPrice'       => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'products'         => $products,
			'shipments'        => $shipments,
		);

		// Virtual and pickup orders carry no shipping address, so fall back
		// to billing throughout the recipient section. Signifyd needs a
		// delivery address to reason about, and an empty one scores worse
		// than a billing address that legitimately matches.
		$has_shipping = $order->get_shipping_address_1() !== '';

		$delivery_address = array(
			'streetAddress' => $has_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
			'unit'          => $has_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
			'city'          => $has_shipping ? $order->get_shipping_city() : $order->get_billing_city(),
			'provinceCode'  => $has_shipping ? $order->get_shipping_state() : $order->get_billing_state(),
			'postalCode'    => $has_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
			'countryCode'   => $has_shipping ? $order->get_shipping_country() : $order->get_billing_country(),
		);

		$recipient = array(
			'fullName'          => $has_shipping
				? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
				: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'confirmationEmail' => $order->get_billing_email(),
			'confirmationPhone' => $order->get_billing_phone(),
			'organization'      => $order->get_billing_company() ? $order->get_billing_company() : null,
			'deliveryAddress'   => $delivery_address,
		);

		// Card expiry, year first: YYMM or YY/MM, with any single separator.
		// The year-first ordering matches Moneris, the default gateway here.
		// A gateway storing MM/YY parses without error but swaps the two
		// fields; correct that with the wc_signifyd_case_payload filter
		// rather than by changing this regex. An unparseable value leaves
		// both fields null, which Signifyd accepts.
		$expiry       = (string) $order->get_meta( $meta_map['card_expiry'] );
		$expiry_year  = null;
		$expiry_month = null;
		if ( preg_match( '#^(\d{2})\D?(\d{2})$#', trim( $expiry ), $m ) ) {
			$expiry_year  = '20' . $m[1];
			$expiry_month = $m[2];
		}

		$card = array(
			'cardHolderName' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'bin'            => $order->get_meta( $meta_map['bin'] ) ? (string) $order->get_meta( $meta_map['bin'] ) : null,
			'last4'          => $order->get_meta( $meta_map['last_four'] ) ? (string) $order->get_meta( $meta_map['last_four'] ) : null,
			'expiryMonth'    => $expiry_month,
			'expiryYear'     => $expiry_year,
			'billingAddress' => array(
				'streetAddress' => $order->get_billing_address_1(),
				'unit'          => $order->get_billing_address_2(),
				'city'          => $order->get_billing_city(),
				'provinceCode'  => $order->get_billing_state(),
				'postalCode'    => $order->get_billing_postcode(),
				'countryCode'   => $order->get_billing_country(),
			),
		);

		// Account history is a strong fraud signal, so send it when the order
		// belongs to a registered customer. Guest checkouts send the section
		// with null fields rather than omitting it.
		$customer_id  = $order->get_customer_id();
		$user_account = array(
			'emailAddress'  => null,
			'username'      => null,
			'phone'         => null,
			'accountNumber' => null,
		);

		if ( $customer_id ) {
			$user = get_userdata( $customer_id );
			if ( $user ) {
				$user_account = array(
					'emailAddress'  => $user->user_email,
					'username'      => $user->user_login,
					'phone'         => $order->get_billing_phone(),
					'accountNumber' => (string) $customer_id,
					'createdDate'   => $user->user_registered
						? gmdate( DateTime::ATOM, strtotime( $user->user_registered ) )
						: null,
				);
			}
		}

		$case = array(
			'purchase'    => $purchase,
			'recipient'   => $recipient,
			'card'        => $card,
			'userAccount' => $user_account,
		);

		/**
		 * Filter the complete case payload before submission.
		 *
		 * @param array    $case
		 * @param WC_Order $order
		 */
		return apply_filters( 'wc_signifyd_case_payload', $case, $order );
	}
}
