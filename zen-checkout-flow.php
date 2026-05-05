<?php
/**
 * Plugin Name: Zen Checkout Flow
 * Description: Popup-based WooCommerce checkout/cart flow for logged-in customers.
 * Version: 0.1.1
 * Author: Custom
 * Text Domain: zen-checkout-flow
 *
 * @package ZenCheckoutFlow
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZCF_Zen_Checkout_Flow' ) ) {
	final class ZCF_Zen_Checkout_Flow {

		const VERSION = '0.1.1';
		const NONCE_ACTION = 'zcf_checkout_flow';

		/**
		 * Boot hooks.
		 */
		public static function init() {
			add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 20 );
		}

		/**
		 * Register plugin hooks.
		 */
		public static function register_hooks() {
			add_shortcode( 'zen_checkout_flow', array( __CLASS__, 'render_shortcode' ) );

			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
			add_action( 'wp_footer', array( __CLASS__, 'render_popup_root' ) );
			add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_add_to_cart_to_popup' ), 20, 2 );
			add_action( 'wp_ajax_zcf_render_checkout', array( __CLASS__, 'ajax_render_checkout' ) );
			add_action( 'wp_ajax_nopriv_zcf_render_checkout', array( __CLASS__, 'ajax_render_checkout' ) );
			add_action( 'wp_ajax_zcf_refresh_checkout', array( __CLASS__, 'ajax_refresh_checkout' ) );
			add_action( 'wp_ajax_zcf_apply_coupon', array( __CLASS__, 'ajax_apply_coupon' ) );
			add_action( 'wp_ajax_zcf_remove_coupon', array( __CLASS__, 'ajax_remove_coupon' ) );
			add_action( 'wp_ajax_zcf_choose_payment_method', array( __CLASS__, 'ajax_choose_payment_method' ) );

			if ( is_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'maybe_dependency_notice' ) );
			}
		}

		/**
		 * Check whether WooCommerce is available.
		 *
		 * @return bool
		 */
		private static function dependencies_loaded() {
			return function_exists( 'WC' ) && class_exists( 'WooCommerce' );
		}

		/**
		 * Admin notice when dependencies are missing.
		 */
		public static function maybe_dependency_notice() {
			if ( self::dependencies_loaded() || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Zen Checkout Flow requires WooCommerce to render cart and checkout data.', 'zen-checkout-flow' );
			echo '</p></div>';
		}

		/**
		 * Register and enqueue frontend assets.
		 */
		public static function register_assets() {
			$base_url = plugin_dir_url( __FILE__ );

			wp_register_style(
				'zcf-checkout-flow',
				$base_url . 'assets/css/zen-checkout-flow.css',
				array(),
				self::VERSION
			);

			wp_register_script(
				'zcf-checkout-flow',
				$base_url . 'assets/js/zen-checkout-flow.js',
				array( 'jquery' ),
				self::VERSION,
				true
			);

			wp_localize_script(
				'zcf-checkout-flow',
				'zcfCheckout',
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
					'checkoutUrl' => self::dependencies_loaded() ? wc_get_checkout_url() : '',
					'cartUrl'     => self::dependencies_loaded() ? wc_get_cart_url() : '',
					'autoOpen'    => self::should_auto_open_popup(),
					'checkoutAjaxUrl' => self::dependencies_loaded() && class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'checkout' ) : '',
					'checkoutNonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
					'myAccountUrl' => self::dependencies_loaded() ? wc_get_page_permalink( 'myaccount' ) : '',
					'customer'    => self::get_checkout_customer_data(),
					'i18n'        => array(
						'loading' => __( 'Updating...', 'zen-checkout-flow' ),
						'error'   => __( 'Something went wrong. Please try again.', 'zen-checkout-flow' ),
						'processing' => __( 'Processing payment...', 'zen-checkout-flow' ),
					),
				)
			);

			if ( ! is_admin() && self::dependencies_loaded() ) {
				wp_enqueue_style( 'zcf-checkout-flow' );
				wp_enqueue_script( 'zcf-checkout-flow' );
			}
		}

		/**
		 * Render checkout flow shortcode.
		 *
		 * @param array $atts Shortcode attributes.
		 * @return string
		 */
		public static function render_shortcode( $atts = array() ) {
			if ( ! self::dependencies_loaded() ) {
				return '<div class="zcf-notice">' . esc_html__( 'WooCommerce is required for this checkout flow.', 'zen-checkout-flow' ) . '</div>';
			}

			wp_enqueue_style( 'zcf-checkout-flow' );
			wp_enqueue_script( 'zcf-checkout-flow' );

			$atts = shortcode_atts(
				array(
					'title' => __( 'Buy now:', 'zen-checkout-flow' ),
				),
				$atts,
				'zen_checkout_flow'
			);

			ob_start();
			?>
			<div class="zcf-shell" data-zcf-checkout-flow>
				<?php echo self::render_frame( $atts['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Keep non-AJAX add-to-cart flows on the current page and open the popup after reload.
		 *
		 * @param string     $url     Redirect URL.
		 * @param WC_Product $product Added product.
		 * @return string
		 */
		public static function redirect_add_to_cart_to_popup( $url, $product = null ) {
			if ( is_admin() || wp_doing_ajax() || ! self::dependencies_loaded() ) {
				return $url;
			}

			$redirect_url = wp_validate_redirect( wp_get_referer(), home_url( '/' ) );

			return add_query_arg( 'zcf_open_checkout', '1', remove_query_arg( array( 'add-to-cart', 'zcf_open_checkout' ), $redirect_url ) );
		}

		/**
		 * Whether the popup should open automatically on page load.
		 *
		 * @return bool
		 */
		private static function should_auto_open_popup() {
			if ( ! self::dependencies_loaded() ) {
				return false;
			}

			$has_open_flag = isset( $_GET['zcf_open_checkout'] ) && '1' === wc_clean( wp_unslash( $_GET['zcf_open_checkout'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_checkout' ) && is_checkout() && ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) )
				|| $has_open_flag;
		}

		/**
		 * Render the plugin-owned popup mount point.
		 */
		public static function render_popup_root() {
			if ( is_admin() || ! self::dependencies_loaded() ) {
				return;
			}

			?>
			<div id="zcf-popup" class="zcf-popup" aria-hidden="true">
				<div class="zcf-popup-backdrop" data-zcf-popup-close></div>
				<div class="zcf-popup-stage" data-zcf-popup-stage>
					<div class="zcf-popup-loading"><?php esc_html_e( 'Loading checkout...', 'zen-checkout-flow' ); ?></div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render checkout shell for the plugin-owned popup.
		 */
		public static function ajax_render_checkout() {
			self::verify_ajax( false );

			wp_send_json_success(
				array(
					'html' => self::render_shell(),
				)
			);
		}

		/**
		 * Render an embeddable checkout shell.
		 *
		 * @return string
		 */
		private static function render_shell() {
			ob_start();
			?>
			<div class="zcf-shell" data-zcf-checkout-flow>
				<?php echo self::render_frame( __( 'Buy now:', 'zen-checkout-flow' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render the full frame.
		 *
		 * @param string $title Payment title.
		 * @return string
		 */
		private static function render_frame( $title ) {
			if ( ! is_user_logged_in() ) {
				return self::render_logged_out();
			}

			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return self::render_empty_cart();
			}

			ob_start();
			?>
			<div class="zcf-modal" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Checkout', 'zen-checkout-flow' ); ?>">
				<div class="zcf-topbar">
					<button type="button" class="zcf-icon-button zcf-back" data-zcf-back aria-label="<?php echo esc_attr__( 'Back', 'zen-checkout-flow' ); ?>"></button>
					<button type="button" class="zcf-icon-button zcf-close" data-zcf-close aria-label="<?php echo esc_attr__( 'Close', 'zen-checkout-flow' ); ?>"></button>
				</div>

				<div class="zcf-grid">
					<section class="zcf-left">
						<?php echo self::render_customer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="zcf-section-title"><?php esc_html_e( 'Your Produkt:', 'zen-checkout-flow' ); ?></div>
						<div class="zcf-cart-items" data-zcf-cart-items>
							<?php echo self::render_cart_items(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</section>

					<section class="zcf-right">
						<h2><?php echo esc_html( $title ); ?></h2>
						<p class="zcf-muted"><?php esc_html_e( 'A confirmation of your purchase will be sent to you by email.', 'zen-checkout-flow' ); ?></p>

						<div class="zcf-payment-panel" data-zcf-payment-panel>
							<?php echo self::render_payment_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>

						<p class="zcf-terms">
							<?php esc_html_e( 'By completing this purchase, you agree to the', 'zen-checkout-flow' ); ?>
							<a href="<?php echo esc_url( wc_get_page_permalink( 'terms' ) ); ?>"><?php esc_html_e( 'terms and conditions.', 'zen-checkout-flow' ); ?></a>
						</p>

						<button type="button" class="zcf-pay-button" data-zcf-pay>
							<?php
							printf(
								/* translators: %s: cart total */
								esc_html__( 'Pay %s', 'zen-checkout-flow' ),
								wp_kses_post( WC()->cart->get_total() )
							);
							?>
						</button>
						<div class="zcf-checkout-result" data-zcf-checkout-result aria-live="polite"></div>
					</section>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Get logged-in customer checkout defaults for AJAX checkout.
		 *
		 * @return array
		 */
		private static function get_checkout_customer_data() {
			if ( ! self::dependencies_loaded() || ! is_user_logged_in() || ! WC()->customer ) {
				return array();
			}

			$customer = WC()->customer;
			$user     = wp_get_current_user();
			$country  = $customer->get_billing_country();

			if ( ! $country && WC()->countries ) {
				$country = WC()->countries->get_base_country();
			}

			return array(
				'billing_first_name' => $customer->get_billing_first_name() ? $customer->get_billing_first_name() : $user->first_name,
				'billing_last_name'  => $customer->get_billing_last_name() ? $customer->get_billing_last_name() : $user->last_name,
				'billing_company'    => $customer->get_billing_company(),
				'billing_country'    => $country,
				'billing_address_1'  => $customer->get_billing_address_1(),
				'billing_address_2'  => $customer->get_billing_address_2(),
				'billing_city'       => $customer->get_billing_city(),
				'billing_state'      => $customer->get_billing_state(),
				'billing_postcode'   => $customer->get_billing_postcode(),
				'billing_phone'      => $customer->get_billing_phone(),
				'billing_email'      => $customer->get_billing_email() ? $customer->get_billing_email() : $user->user_email,
			);
		}

		/**
		 * Render logged-out state.
		 *
		 * @return string
		 */
		private static function render_logged_out() {
			ob_start();
			?>
			<div class="zcf-modal zcf-state">
				<div class="zcf-state-inner">
					<h2><?php esc_html_e( 'Please log in to view your cart.', 'zen-checkout-flow' ); ?></h2>
					<p><?php esc_html_e( 'This checkout flow is available only for logged-in customers.', 'zen-checkout-flow' ); ?></p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render empty cart state.
		 *
		 * @return string
		 */
		private static function render_empty_cart() {
			ob_start();
			?>
			<div class="zcf-modal zcf-state">
				<div class="zcf-topbar">
					<button type="button" class="zcf-icon-button zcf-back" data-zcf-back aria-label="<?php echo esc_attr__( 'Back', 'zen-checkout-flow' ); ?>"></button>
					<button type="button" class="zcf-icon-button zcf-close" data-zcf-close aria-label="<?php echo esc_attr__( 'Close', 'zen-checkout-flow' ); ?>"></button>
				</div>
				<div class="zcf-state-inner">
					<h2><?php esc_html_e( 'Your cart is empty.', 'zen-checkout-flow' ); ?></h2>
					<p><?php esc_html_e( 'Add a membership, booking, or product before opening checkout.', 'zen-checkout-flow' ); ?></p>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render customer block.
		 *
		 * @return string
		 */
		private static function render_customer() {
			$user = wp_get_current_user();

			ob_start();
			?>
			<div class="zcf-customer">
				<?php echo get_avatar( $user->ID, 72, '', '', array( 'class' => 'zcf-avatar' ) ); ?>
				<div>
					<div class="zcf-customer-name"><?php echo esc_html( self::get_customer_name( $user ) ); ?></div>
					<div class="zcf-customer-email"><?php echo esc_html( $user->user_email ); ?></div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Get customer display name.
		 *
		 * @param WP_User $user User.
		 * @return string
		 */
		private static function get_customer_name( $user ) {
			$name = trim( $user->first_name . ' ' . $user->last_name );

			return $name ? $name : $user->display_name;
		}

		/**
		 * Render cart items.
		 *
		 * @return string
		 */
		private static function render_cart_items() {
			ob_start();

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$product = $cart_item['data'];

				if ( ! $product || ! $product->exists() ) {
					continue;
				}

				$quantity = max( 1, (int) $cart_item['quantity'] );
				?>
				<article class="zcf-product-card" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>">
					<div class="zcf-product-main">
						<div>
							<h3><?php echo esc_html( $product->get_name() ); ?></h3>
							<?php echo self::render_product_meta( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="zcf-product-price">
							<strong><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $quantity ) ); ?></strong>
							<?php echo self::render_price_suffix( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>

					<button type="button" class="zcf-product-cta" data-zcf-to-payment>
						<?php esc_html_e( 'To Payment', 'zen-checkout-flow' ); ?>
					</button>

					<details class="zcf-more">
						<summary><?php esc_html_e( 'more information', 'zen-checkout-flow' ); ?></summary>
						<div class="zcf-more-body">
							<?php echo wp_kses_post( wc_get_formatted_cart_item_data( $cart_item ) ); ?>
						</div>
					</details>
				</article>
				<?php
			}

			return ob_get_clean();
		}

		/**
		 * Render product meta line.
		 *
		 * @param array $cart_item Cart item.
		 * @return string
		 */
		private static function render_product_meta( $cart_item ) {
			$product = $cart_item['data'];
			$lines   = array();

			if ( $product && $product->is_type( array( 'subscription', 'subscription_variation' ) ) && class_exists( 'WC_Subscriptions_Product' ) ) {
				$period = WC_Subscriptions_Product::get_period( $product );
				if ( $period ) {
					$lines[] = sprintf(
						/* translators: %s: billing period */
						__( 'Contract term: %s', 'zen-checkout-flow' ),
						$period
					);
				}
			}

			if ( empty( $lines ) ) {
				$lines[] = sprintf(
					/* translators: %d: quantity */
					__( 'Quantity: %d', 'zen-checkout-flow' ),
					max( 1, (int) $cart_item['quantity'] )
				);
			}

			return '<div class="zcf-product-meta">' . esc_html( implode( ' · ', $lines ) ) . '</div>';
		}

		/**
		 * Render price suffix.
		 *
		 * @param WC_Product $product Product.
		 * @return string
		 */
		private static function render_price_suffix( $product ) {
			if ( ! $product || ! class_exists( 'WC_Subscriptions_Product' ) || ! WC_Subscriptions_Product::is_subscription( $product ) ) {
				return '';
			}

			$period = WC_Subscriptions_Product::get_period( $product );

			return $period ? '<span>/ ' . esc_html( $period ) . '</span>' : '';
		}

		/**
		 * Render payment panel.
		 *
		 * @return string
		 */
		private static function render_payment_panel() {
			$available_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();
			$chosen_gateway     = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';

			if ( ! $chosen_gateway && $available_gateways ) {
				$gateway_ids    = array_keys( $available_gateways );
				$chosen_gateway = reset( $gateway_ids );
			}

			ob_start();
			?>
			<div class="zcf-panel-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ) ) ); ?></div>

			<form class="zcf-coupon" data-zcf-coupon>
				<input type="text" name="coupon_code" placeholder="<?php echo esc_attr__( 'Discount Code', 'zen-checkout-flow' ); ?>" autocomplete="off" />
				<button type="submit"><?php esc_html_e( 'Apply', 'zen-checkout-flow' ); ?></button>
			</form>

			<?php echo self::render_applied_coupons(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="zcf-summary">
				<strong><?php esc_html_e( 'Order Summary', 'zen-checkout-flow' ); ?> <span><?php esc_html_e( '(incl. VAT):', 'zen-checkout-flow' ); ?></span></strong>
				<b><?php echo wp_kses_post( WC()->cart->get_total() ); ?></b>
			</div>

			<div class="zcf-payment-label"><?php esc_html_e( 'Payment method:', 'zen-checkout-flow' ); ?></div>

			<div class="zcf-gateways" data-zcf-gateways>
				<?php if ( $available_gateways ) : ?>
					<?php foreach ( $available_gateways as $gateway_id => $gateway ) : ?>
						<label class="zcf-gateway <?php echo checked( $chosen_gateway, $gateway_id, false ) ? 'is-selected' : ''; ?>">
							<input type="radio" name="payment_method" value="<?php echo esc_attr( $gateway_id ); ?>" <?php checked( $chosen_gateway, $gateway_id ); ?> />
							<span><?php echo esc_html( $gateway->get_title() ); ?></span>
							<em><?php echo wp_kses_post( $gateway->get_icon() ); ?></em>
						</label>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="zcf-no-gateways"><?php esc_html_e( 'No payment methods are available for this order.', 'zen-checkout-flow' ); ?></div>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render applied coupons.
		 *
		 * @return string
		 */
		private static function render_applied_coupons() {
			$coupons = WC()->cart ? WC()->cart->get_applied_coupons() : array();

			if ( empty( $coupons ) ) {
				return '';
			}

			ob_start();
			?>
			<div class="zcf-applied-coupons">
				<?php foreach ( $coupons as $coupon_code ) : ?>
					<button type="button" data-zcf-remove-coupon="<?php echo esc_attr( $coupon_code ); ?>">
						<?php echo esc_html( $coupon_code ); ?>
						<span aria-hidden="true">×</span>
					</button>
				<?php endforeach; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Refresh checkout fragments.
		 */
		public static function ajax_refresh_checkout() {
			self::verify_ajax();
			self::send_fragments();
		}

		/**
		 * Apply coupon.
		 */
		public static function ajax_apply_coupon() {
			self::verify_ajax();

			$coupon_code = isset( $_POST['coupon_code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( '' === $coupon_code ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a discount code.', 'zen-checkout-flow' ) ) );
			}

			$applied = WC()->cart->apply_coupon( $coupon_code );
			WC()->cart->calculate_totals();

			if ( ! $applied ) {
				wp_send_json_error( array( 'message' => wc_print_notices( true ) ) );
			}

			self::send_fragments();
		}

		/**
		 * Remove coupon.
		 */
		public static function ajax_remove_coupon() {
			self::verify_ajax();

			$coupon_code = isset( $_POST['coupon_code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( '' === $coupon_code ) {
				wp_send_json_error( array( 'message' => __( 'Missing coupon code.', 'zen-checkout-flow' ) ) );
			}

			WC()->cart->remove_coupon( $coupon_code );
			WC()->cart->calculate_totals();

			self::send_fragments();
		}

		/**
		 * Store selected payment method in the WooCommerce session.
		 */
		public static function ajax_choose_payment_method() {
			self::verify_ajax();

			$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$gateways       = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();

			if ( '' === $payment_method || ! isset( $gateways[ $payment_method ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Please choose an available payment method.', 'zen-checkout-flow' ) ) );
			}

			if ( WC()->session ) {
				WC()->session->set( 'chosen_payment_method', $payment_method );
			}

			wp_send_json_success();
		}

		/**
		 * Verify AJAX request.
		 */
		private static function verify_ajax( $require_login = true ) {
			if ( ! self::dependencies_loaded() ) {
				wp_send_json_error( array( 'message' => __( 'WooCommerce is unavailable.', 'zen-checkout-flow' ) ) );
			}

			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( $require_login && ! is_user_logged_in() ) {
				wp_send_json_error( array( 'message' => __( 'Please log in to continue.', 'zen-checkout-flow' ) ) );
			}
		}

		/**
		 * Send refreshed fragments.
		 */
		private static function send_fragments() {
			wp_send_json_success(
				array(
					'cartItems'     => self::render_cart_items(),
					'paymentPanel'  => self::render_payment_panel(),
					'payButtonText' => sprintf(
						/* translators: %s: cart total */
						__( 'Pay %s', 'zen-checkout-flow' ),
						wp_strip_all_tags( WC()->cart->get_total() )
					),
				)
			);
		}
	}

	ZCF_Zen_Checkout_Flow::init();
}
