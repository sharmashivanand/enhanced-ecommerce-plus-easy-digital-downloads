<?php
/**
 * Enhanced Ecommerce Plus for Easy Digital Downloads
 *
 * @package     Enhanced Ecommerce Plus for Easy Digital Downloads
 * @author      Shivanand Sharma
 * @copyright   2022 converticacommerce.com
 * @license     MIT
 *
 * @wordpress-plugin
 * Plugin Name: Enhanced Ecommerce Plus for Easy Digital Downloads
 * Description: Implement Google Analytics Enhanced Ecommerce Tracking on your Easy Digital Downloads store
 * Version:     1.1
 * Author:      Shivanand Sharma
 * Author URI:  https://converticacommerce.com
 * Text Domain: eepedd
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Plugin URI:  https://wp-social-proof.com
 */

/**
 * RESOURCES / REFERENCES
 * https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters
 * https://enhancedecommerce.appspot.com/
 * https://developers.google.com/analytics/devguides/collection/analyticsjs/enhanced-ecommerce
 * https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtag
 * https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm
 * https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference/events
 * https://developers.google.com/tag-platform/gtagjs/reference
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EEPEDD_PLUGIN_DIR_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'EEPEDD_PLUGIN', __FILE__ );
define( 'EEPEDD_PLUGIN_DIR', trailingslashit( __DIR__ ) );

final class EEPEDD_Init {

	private $dir;
	private $url;
	private $cap                     = 'activate_plugins';
	private $track_detail_flag       = 0;
	private $impressions             = array();
	private $ga_measurement_ep       = 'https://ssl.google-analytics.com/collect';
	private $ga_debug_measurement_ep = 'https://www.google-analytics.com/debug/collect';

	static function get_instance() {
		static $instance = null;
		if ( is_null( $instance ) ) {
			$instance = new self();
			$instance->init();
		}
		return $instance;
	}

	function init() {

		$this->dir = trailingslashit( plugin_dir_path( __FILE__ ) );
		$this->url = trailingslashit( plugin_dir_url( __FILE__ ) );

		add_action( 'admin_menu', array( $this, 'settings_menu' ) );
		add_action( 'wp_ajax_eepeed_save_property_id', array( $this, 'settings_handler' ) );

		add_action( 'edd_purchase_link_end', array( $this, 'add_singular_impression' ), 10, 2 );
		add_action( 'edd_pre_add_to_cart', array( $this, 'add_to_cart' ), 10, 2 );
		add_action( 'edd_pre_remove_from_cart', array( $this, 'remove_from_cart' ), 10, 1 );
		add_action( 'edd_before_checkout_cart', array( $this, 'checkout_page_step' ) );
		add_action( 'edd_complete_purchase', array( $this, 'checkout_complete_step' ) );
		add_action( 'edd_complete_purchase', array( $this, 'transaction' ), 10, 3 );
		add_action( 'edd_post_refund_payment', array( $this, 'refund' ), 10 );
		add_action( 'edd_insert_payment', array( $this, 'save_user_cid' ), 10, 2 );
		add_action( 'edd_purchase_link_end', array( $this, 'add_non_singular_impression' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'track_impressions' ) );

	}

	function add_singular_impression( $download_id, $args ) {

		// Return if NOT a detail page
		if ( ! is_singular( 'download' ) ) {
			return;
		}

		// Return if this product detail is already tracked. Prevents
		// double tracking as there could be multiple buy buttons on the page.
		if ( isset( $this->track_detail_flag ) && $this->track_detail_flag >= 1 ) {
			return;
		} else {
			$this->track_detail_flag = 1;
		}

		$download       = new EDD_Download( $download_id );
		$categories     = (array) get_the_terms( $download->ID, 'download_category' );
		$category_names = wp_list_pluck( $categories, 'name' );
		$first_category = reset( $category_names );

		$params = array(
			't'     => 'event', // Hit Type
			'ec'    => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
			'ea'    => 'Detail', // Event Action
			'el'    => 'Product Detail View', // Event Label
			'pa'    => 'detail', // Product Action
			'pal'   => '', // Product Action List
			'pr1id' => $download->ID, // Product SKU
			'pr1nm' => $download->post_title, // Product Name
			'pr1ca' => $first_category, // Product Category
		);

		$this->fire( $params );
	}

	function add_to_cart( $download_id, $options ) {

		$download       = new EDD_Download( $download_id );
		$price_options  = $download->get_prices();
		$variation      = isset( $options['price_id'] ) && isset( $price_options[ $options['price_id'] ] ) ? $price_options[ $options['price_id'] ]['name'] : '';
		$price          = isset( $options['price_id'] ) && isset( $price_options[ $options['price_id'] ] ) ? $price_options[ $options['price_id'] ]['amount'] : '';
		$price          = empty( $price ) ? $download->get_price() : $price;
		$quantity       = isset( $options['quantity'] ) ? $options['quantity'] : 1;
		$categories     = (array) get_the_terms( $download->ID, 'download_category' );
		$category_names = wp_list_pluck( $categories, 'name' );
		$first_category = reset( $category_names );

		$parms = array(
			't'     => 'event', // Hit Type
			'ec'    => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
			'ea'    => 'Add', // Event Action
			'el'    => edd_get_option( 'add_to_cart_text', 'Add To Cart' ), // Event Label
			'ev'    => $quantity, // Event Value
			'pa'    => 'add', // Product Action
			'pal'   => '', // Product Action List
			'pr1id' => $download->ID, // Product SKU
			'pr1nm' => $download->post_title, // Product Name
			'pr1ca' => $first_category, // Product Category
			'pr1va' => $variation, // Product Variant
			'pr1pr' => $price, // Product Price
			'pr1qt' => $quantity, // Product Quantity
		);

		$this->fire( $parms );
	}

	function remove_from_cart( $cart_key ) {

		$cart_contents = edd_get_cart_contents();

		if ( ! isset( $cart_contents[ $cart_key ] ) ) {
			return;
		}

		$download       = new EDD_Download( $cart_contents[ $cart_key ]['id'] );
		$price_options  = $download->get_prices();
		$price_id       = isset( $cart_contents[ $cart_key ]['options']['price_id'] ) ? $cart_contents[ $cart_key ]['options']['price_id'] : null;
		$variation      = isset( $price_id ) && isset( $price_options[ $price_id ] ) ? $price_options[ $price_id ]['name'] : '';
		$price          = isset( $price_id ) && isset( $price_options[ $price_id ] ) ? $price_options[ $price_id ]['amount'] : '';
		$price          = empty( $price ) ? $download->get_price() : $price;
		$quantity       = isset( $cart_contents[ $cart_key ]['quantity'] ) ? $cart_contents[ $cart_key ]['quantity'] : 1;
		$categories     = (array) get_the_terms( $download->ID, 'download_category' );
		$category_names = wp_list_pluck( $categories, 'name' );
		$first_category = reset( $category_names );

		$parms = array(
			't'     => 'event', // Hit Type
			'ec'    => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
			'ea'    => 'Remove', // Event Action
			'el'    => 'Remove From Cart', // Event Label
			'ev'    => $quantity, // Event Value
			'pa'    => 'remove', // Produst Action
			'pal'   => '', // Product Action List
			'pr1id' => $download->ID, // Product SKU
			'pr1nm' => $download->post_title, // Product Name
			'pr1ca' => $first_category, // Product Category
			'pr1va' => $variation, // Product Variant
			'pr1pr' => $price, // Product Price
			'pr1qt' => $quantity, // Product Quantity
		);

		$this->fire( $parms );
	}

	function checkout_page_step() {

		// Return if its not the checkout page
		if ( ! edd_is_checkout() ) {
			return;
		}
		$items = $this->get_cart_items();

		$parms = array_merge(
			array(
				't'   => 'event', // Hit Type
				'ec'  => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
				'ea'  => 'Checkout', // Event Action
				'el'  => 'checkout page', // Event Label
				'pa'  => 'checkout', // Product Action
				'cos' => '1', // Checkout Step
				'col' => edd_get_gateway_admin_label( edd_get_chosen_gateway() ), // Checkout Step Option

			),
			$items
		);

		$this->fire( $parms );
	}

	function checkout_complete_step( $payment_id ) {

		$items = $this->fetch_download_items( $payment_id );

		$parms = array_merge(
			array(
				't'   => 'event', // Hit Type
				'ec'  => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
				'ea'  => 'Checkout', // Event Action
				'el'  => 'complete', // Event Label
				'cid' => $this->get_cid( $payment_id ), // Client ID
				'pa'  => 'checkout', // Product Action
				'cos' => '2', // Checkout Step
				'col' => '', // Checkout Step Option

			),
			$items
		);

		$this->fire( $parms );
	}

	function fetch_download_items( $payment_id ) {

		$c            = 0;
		$items        = array();
		$payment_meta = edd_get_payment_meta( $payment_id );

		if ( $payment_meta['cart_details'] ) {
			foreach ( $payment_meta['cart_details'] as $key => $item ) {
				$download       = new EDD_Download( $item['id'] );
				$price_options  = $download->get_prices();
				$price_id       = isset( $item['item_number']['options']['price_id'] ) ? $item['item_number']['options']['price_id'] : null;
				$variation      = ! empty( $price_id ) && isset( $price_options[ $price_id ] ) ? $price_options[ $price_id ]['name'] : '';
				$categories     = (array) get_the_terms( $item['id'], 'download_category' );
				$category_names = wp_list_pluck( $categories, 'name' );
				$first_category = reset( $category_names );
				$author         = get_the_author_meta( 'display_name', $download->post_author );

				$c++;
				$items[ "pr{$c}id" ]  = $item['id']; // Product SKU
				$items[ "pr{$c}nm" ]  = $item['name']; // Product Name
				$items[ "pr{$c}ca" ]  = $first_category; // Product Category
				$items[ "pr{$c}pr" ]  = $item['price']; // Product Price
				$items[ "pr{$c}qt" ]  = $item['quantity']; // Product Quantity
				$items[ "pr{$c}va" ]  = $variation; // Product Variant
				$items[ "pr{$c}cd1" ] = $author; // Product Custom Dimension
			}
		}

		return $items;
	}

	function get_cart_items() {

		$c             = 0;
		$items         = array();
		$cart_contents = edd_get_cart_content_details();

		if ( $cart_contents ) {

			foreach ( $cart_contents as $key => $item ) {

				$download       = new EDD_Download( $item['id'] );
				$price_options  = $download->get_prices();
				$price_id       = isset( $item['item_number']['options']['price_id'] ) ? $item['item_number']['options']['price_id'] : null;
				$variation      = ! empty( $price_id ) && isset( $price_options[ $price_id ] ) ? $price_options[ $price_id ]['name'] : '';
				$categories     = (array) get_the_terms( $item['id'], 'download_category' );
				$category_names = wp_list_pluck( $categories, 'name' );
				$first_category = reset( $category_names );
				$author         = get_the_author_meta( 'display_name', $download->post_author );

				$c++;
				$items[ "pr{$c}id" ]  = $item['id']; // Product SKU
				$items[ "pr{$c}nm" ]  = $item['name']; // Product Name
				$items[ "pr{$c}ca" ]  = $first_category; // Product Category
				$items[ "pr{$c}pr" ]  = $item['price']; // Product Price
				$items[ "pr{$c}qt" ]  = $item['quantity']; // Product Quantity
				$items[ "pr{$c}va" ]  = $variation; // Product Variant
				$items[ "pr{$c}cd1" ] = $author; // Product Custom Dimension
			}
		}

		return $items;

	}

	// https://web.archive.org/web/20180623175804/http://www.stumiller.me/implementing-google-analytics-measurement-protocol-in-php-and-wordpress/
	function save_user_cid( $payment_id, $payment_data ) {

		if ( isset( $_COOKIE['_ga'] ) ) {
			list( $version, $domainDepth, $cid1, $cid2 ) = preg_split( '[\.]', sanitize_text_field( $_COOKIE['_ga'] ), 4 );
			$contents                                    = array(
				'version'     => $version,
				'domainDepth' => $domainDepth,
				'cid'         => $cid1 . '.' . $cid2,
			);
			$cid = $contents['cid'];
			update_post_meta( $payment_id, '_eepedd_cid', $cid );
		}
	}

	function transaction( $payment_id, $payment = '', $customer = '' ) {
		$this->flog( __FUNCTION__ . ' init' );
		$this->flog( $payment_id );
		$this->flog( $payment );
		$this->flog( $customer );
		if ( get_post_meta( $payment_id, '_eepedd_tracked', true ) ) {
			return;
		}

		$payment_meta = edd_get_payment_meta( $payment_id );

		$items    = $this->fetch_download_items( $payment_id ); // fetch details of items bought.
		$discount = $payment_meta['user_info']['discount'];
		$discount = $discount != 'none' ? explode( ',', $discount ) : null;
		$discount = is_array( $discount ) ? reset( $discount ) : $discount;

		$parms = array_merge(
			array(
				't'   => 'event', // Hit Type
				'ec'  => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
				'ea'  => 'Checkout', // Event Action
				'el'  => 'Transaction', // Event Label
				'cid' => $this->get_cid( $payment_id ), // Client ID
				'ti'  => edd_get_payment_number( $payment_id ), // Transaction ID
				'ta'  => null, // Transaction Affiliation
				'tr'  => edd_get_payment_amount( $payment_id ), // Transaction Revenue
				'cu'  => edd_get_payment_currency_code( $payment_id ), // Currency Code
				'tt'  => edd_use_taxes() ? edd_get_payment_tax( $payment_id ) : null, // Transaction Tax
				'ts'  => null, // Shipping
				'tcc' => $discount, // Coupon Code
				'pa'  => 'purchase', // Product Action
			),
			$items
		);

		$this->fire( $parms );
		@update_post_meta( $payment_id, '_eepedd_tracked', 'complete' );
		$this->flog( $parms );
		$this->flog( __FUNCTION__ . ' end' );
	}

	function refund( $payment ) {
		$this->flog( __FUNCTION__ . ' init' );
		if ( 'refund' == get_post_meta( $payment->ID, '_eepedd_tracked', true ) ) {
			return;
		}

		$parms = array(
			't'  => 'event', // Hit type
			'ec' => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
			'ea' => 'Refund', // Event Action
			'ti' => $payment->number, // Transaction ID
			'pa' => 'refund', // Product Action
		);

		$this->fire( $parms );
		@update_post_meta( $payment_id, '_eepedd_tracked', 'refund' );
		$this->flog( __FUNCTION__ . ' end' );

	}

	function add_non_singular_impression( $download_id, $args ) {

		// Return if its detail single page.
		if ( is_singular( 'download' ) ) {
			return;
		}

		$download       = new EDD_Download( $download_id );
		$categories     = (array) get_the_terms( $download->ID, 'download_category' );
		$category_names = wp_list_pluck( $categories, 'name' );
		$first_category = reset( $category_names );
		$c              = count( $this->impressions ) + 1;

		// Prevent duplicate impressions on one page
		if ( isset( $this->impressions[ $download->ID ] ) ) {
			return;
		}

		$this->impressions[ $download->ID ] = array(
			"il{$c}nm"     => 'Complete_List', // Product Impression List Name
			"il{$c}pi1id"  => $download->ID, // Product Impression SKU
			"il{$c}pi1nm"  => $download->post_title, // Product Impression Name
			"il{$c}pi1ca"  => $first_category, // Product Impression Category
			"il{$c}pi1br"  => '', // Product Impression Brand
			"il{$c}pi1va"  => '', // Product Impression Variant
			"il{$c}pi1ps"  => '', // Product Impression Position
			"il{$c}pi1cd1" => get_the_author_meta( 'display_name', $download->post_author ), // Product Impression Custom Dimension
		);

	}

	function track_impressions() {

		// Return if its detail page.
		if ( is_singular( 'download' ) ) {
			return;
		}

		// Return if impressions are empty
		if ( empty( $this->impressions ) ) {
			return;
		}

		$impressions = array();
		foreach ( $this->impressions as $key => $value ) {
			$impressions = array_merge( $impressions, $value );
		}

		$parms = array_merge(
			array(
				't'  => 'event', // Hit Type
				'ec' => 'Enhanced Ecommerce Plus for Easy Digital Downloads Tracking', // Event Category
				'ea' => 'Impression', // Event Action
				'el' => 'Impression', // Event Label
			),
			$impressions
		);

		$this->fire( $parms );
	}

	function fire( $params ) {
		$this->flog( __FUNCTION__ . ' init' );
		$this->flog( $params );
		if ( ! $property_id = $this->get_setting( 'property_id' ) ) {
			// $this->flog( 'Either user is Administrator or property_id not set' );
			// $this->flog( current_user_can( 'administrator' ) );
			$this->flog( 'Property_id not set' );
			$this->flog( $this->get_setting( 'property_id' ) );
			return;
		}

		$default_parms = array(
			't'  => 'event', // Hit type
			'ec' => '', // Event Category
			'ea' => '', // Event Action
			'el' => '', // Event Label
			'ev' => null, // Event Value
		);

		$body = array_merge( $default_parms, $params );

		$ip = $this->get_ip();

		$user_language = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? explode( ',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) : array();
		$user_language = strtolower( reset( $user_language ) );

		$default_body = array(
			'v'   => '1', // Protocol Version
			'tid' => $property_id, // Tracking ID/ Web Property ID
			'cid' => $this->get_cid(), // Client ID
			't'   => 'pageview', // Hit type
			'ni'  => true, // Non-Interaction Hit
			// 'aip' => 1, // Anonymize IP
			'dh'  => parse_url( site_url(), PHP_URL_HOST ), // Document Host Name
			'dp'  => ! empty( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '', // Document Path
			'dt'  => get_the_title(), // Document Title

			// Hits that usually also go with JS
			'ul'  => $user_language, // User Language
			'uip' => $ip, // IP Override
			'ua'  => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : 'UNKNOWN_HTTP_USER_AGENT', // User Agent Override
		);

		$body = wp_parse_args( $body, $default_body );

		$this->flog( 'Sanitizing body' );
		$this->flog( $body );
		foreach ( $body as $key => $value ) {
			$body[ $key ] = $this->sanitize( $value );
		}

		// Requests without ID are ignored by GA
		if ( false == $body['cid'] ) {
			$this->flog( 'Empty cid' );
			return false;
		}

		$body = array_merge( $body, array( 'z' => time() ) );
		if ( empty( $body['ev'] ) ) {
			unset( $body['ev'] );
		}

		// Log the actual hit so that it's reported in Google Analytics reports.
		$response = wp_remote_post(
			$this->ga_measurement_ep,
			array(
				'method'   => 'POST',
				'blocking' => false,
				'body'     => $body,
			)
		);

		// For the future, implement debug setting: a way to validate hits and see the results

		// DEBUG STUFF DOESN'T SHOW UP IN GA REPORTS
		// Important: hits sent to the Measurement Protocol Validation Server will not show up in reports. They are for debugging only.
		// https://developers.google.com/analytics/devguides/collection/protocol/v1/validating-hits
		// $eepedd_debug_pre_fire = @update_post_meta( $payment_id, '_eepedd_debug_pre_fire', $body );
		$this->flog( 'Sending body to GA' );
		$this->flog( $body );
		$response = wp_remote_post(
			$this->ga_debug_measurement_ep,
			array(
				'method'   => 'POST',
				'blocking' => true, // because we need the results for (optionally) pushing into the log
				'body'     => $body,
			)
		);
		$response = wp_remote_retrieve_body( $response );
		// $eepedd_debug_post_fire = @update_post_meta( $payment_id, '_eepedd_debug_post_fire', $response );
		$this->flog( 'Received response from GA' );
		$this->flog( $response );

	}

	function sanitize( $str ) {
		
		$this->flog( $str );
		$str = @html_entity_decode( $str );  // convert all html entities back to the actual characters
		$str = str_replace( '&', '%26', $str ); // replace & with a space else GA interprets them as parameters and throws warnings about invalid parameters
		return $str;
	}

	function get_ip() {
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
		};

		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $key ) {
			if ( array_key_exists( $key, $_SERVER ) ) {
				foreach ( explode( ',', $_SERVER[ $key ] ) as $ip ) {
					$ip = trim( $ip );

					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
								return $ip;
					};
				};
			};
		};

		return '127.0.0.1'; // let's return a valid IP at least so that it doesn't break GA logging just in case
	}


	public function get_cid( $payment_id = '' ) {

		if ( ! empty( $_COOKIE['_ga'] ) ) {
			list( $version, $domainDepth, $cid1, $cid2 ) = preg_split( '[\.]', sanitize_text_field( $_COOKIE['_ga'] ), 4 );
			$contents                                    = array(
				'version'     => $version,
				'domainDepth' => $domainDepth,
				'cid'         => $cid1 . '.' . $cid2,
			);
			$cid = $contents['cid'];
			$this->flog( 'Returning cid from' );
			$this->flog( $cid );
			return $cid;
		} elseif ( ! empty( $payment_id ) && $saved_cid = get_post_meta( $payment_id, '_eepedd_cid', true ) ) {
			$this->flog( 'Returning _eepedd_cid from post meta' );
			$this->flog( $saved_cid );
			return $saved_cid;
		} else {
			$cid = $this->gaGenUUID();
			$this->flog( 'Returning generated cid' );
			$this->flog( $cid );
			return $cid;
		}
	}

	function gaGenUUID() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			// 16 bits for "time_mid"
			mt_rand( 0, 0xffff ),
			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand( 0, 0x0fff ) | 0x4000,
			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand( 0, 0x3fff ) | 0x8000,
			// 48 bits for "node"
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff )
		);
	}

	function settings_menu() {
		add_menu_page(
			'Enhanced Ecom EDD',
			'Enhanced Ecom EDD',
			$this->cap,
			'eepedd',
			array( $this, 'config_ui' ),
			'',
			89
		);
	}

	function config_ui() {
		?>
		<div class="wrap">
		<h1 id="page_title">EDD Enhaced Ecommerce Settings</h1>
		<table><tr>
			<td><label for="eepeed_property_id">Google Analytics Web Property ID</label></td>
			<td><input type="text" name="eepeed_property_id" id="eepeed_property_id" value="<?php echo esc_html( $this->get_setting( 'property_id' ) ); ?>" placeholder="UA-XXXXXXXXX-X" pattern="[a-zA-Z0-9]+" /></td>
			<td id="eepeed_property_id_setting_status"><?php submit_button( 'Save', 'primary', 'eepeed_submit', true ); ?></td>
		</tr></table>
		<script type="text/javascript">
		//<![CDATA[
		jQuery(document).ready(function($) {
			console.log('ready');
			$('#eepeed_submit').click(function(event){
				eepeed_save_property_id = {
					eepeed_save_property_id_nonce: '<?php echo wp_create_nonce( 'eepeed_save_property_id' ); ?>',
					action: "eepeed_save_property_id",
					property_id: $('#eepeed_property_id').val()
				};
				$.ajax({
						url: ajaxurl,
						method: 'POST',
						data: eepeed_save_property_id,
						success: function(data,textStatus,jqXHR){
							console.dir('success');
							console.dir(data);
							console.dir(textStatus);
							console.dir(jqXHR);

							if( data.hasOwnProperty('success') && data.success ) {
								$('#eepeed_property_id').css('background-color', '#afa');
							}
							else {
								$('#eepeed_property_id').css('background-color', '#faa');
							}
						},
						error: function(jqXHR, textStatus, errorThrown){
							// ajax request didn't make it
							console.dir('error');
							console.dir(jqXHR);
							console.dir(textStatus);
							console.dir(errorThrown);
							$('#eepeed_property_id').css('background-color', '#ffa');
						}, 
						complete: function(jqXHR, textStatus) {
							console.dir('complete');
							console.dir(jqXHR);
							console.dir(textStatus);
						}
					}); // ajax post
			});
		});
		</script>
		<?php

	}

	function get_setting( $setting ) {
		$settings = get_option( 'EEPEDD' );
		return isset( $settings[ $setting ] ) ? $settings[ $setting ] : false;
	}

	function update_setting( $setting, $value ) {
		$settings = get_option( 'EEPEDD' );
		if ( ! $settings ) {
			$settings = array();
		}
		$settings[ $setting ] = $value;
		return update_option( 'EEPEDD', $settings );
	}

	function delete_setting( $setting ) {
		$settings = get_option( 'EEPEDD' );
		if ( ! $settings ) {
			$settings = array();
		}
		unset( $settings[ $setting ] );
		update_option( 'EEPEDD', $settings );
	}

	function settings_handler() {
		check_ajax_referer( 'eepeed_save_property_id', 'eepeed_save_property_id_nonce' );
		if ( ! empty( $_REQUEST['property_id'] ) ) { // update setting
			// ^[A-Z][A-Z0-9]?-[A-Z0-9]{4,10}(?:\-[1-9]\d{0,3})?$/ https://stackoverflow.com/a/68679610
			if ( preg_match( '/\bUA\-\d{4,10}\-\d{1,4}\b/', trim( $_REQUEST['property_id'] ) ) ) {
				$this->update_setting( 'property_id', sanitize_text_field( $_REQUEST['property_id'] ) );
				wp_send_json_success();
			} else {
				wp_send_json_error( 'Invalid GA Property ID Pattern' );
			}
		} else { // delete setting
			$this->delete_setting( 'property_id' );
		}
		wp_send_json_error( 'Invalid Nonce' );
	}

	function llog( $str ) {
		echo '<pre>';
		print_r( $str );
		echo '</pre>';
	}

	function flog( $str, $file = 'log.log', $timestamp = false ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$date = date( 'Ymd-G:i:s' ); // 20171231-23:59:59
			$date = $date . '-' . microtime( true );
			$file = $this->dir . sanitize_text_field( $file );
			if ( $timestamp ) {
				@file_put_contents( $file, PHP_EOL . $date, FILE_APPEND | LOCK_EX );
			}
			$str = print_r( $str, true );
			@file_put_contents( $file, PHP_EOL . $str, FILE_APPEND | LOCK_EX );
		}
	}

}

function eepedd() {
	return EEPEDD_Init::get_instance();
}

eepedd();
