<?php
/**
 * Plugin Name: Zen Checkout Flow
 * Description: Popup-based WooCommerce checkout/cart flow for logged-in customers.
 * Version: 0.1.46
 * Author: Custom
 * Text Domain: zen-checkout-flow
 *
 * @package ZenCheckoutFlow
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZCF_Zen_Checkout_Flow' ) ) {
	final class ZCF_Zen_Checkout_Flow {

		const VERSION = '0.1.46';
		const NONCE_ACTION = 'zcf_checkout_flow';
		private static $native_card_bootstrap_summary = null;

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
			add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_native_checkout_route' ), 5 );
			add_action( 'wp_footer', array( __CLASS__, 'render_popup_root' ) );
			add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );
			add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_popup_payment_gateways' ), 50 );
			add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_add_to_cart_to_popup' ), 20, 2 );
			add_action( 'wp_ajax_zcf_render_checkout', array( __CLASS__, 'ajax_render_checkout' ) );
			add_action( 'wp_ajax_nopriv_zcf_render_checkout', array( __CLASS__, 'ajax_render_checkout' ) );
			add_action( 'wp_ajax_zcf_refresh_checkout', array( __CLASS__, 'ajax_refresh_checkout' ) );
			add_action( 'wp_ajax_zcf_apply_coupon', array( __CLASS__, 'ajax_apply_coupon' ) );
			add_action( 'wp_ajax_zcf_remove_coupon', array( __CLASS__, 'ajax_remove_coupon' ) );
			add_action( 'wp_ajax_zcf_remove_cart_item', array( __CLASS__, 'ajax_remove_cart_item' ) );
			add_action( 'wp_ajax_zcf_choose_payment_method', array( __CLASS__, 'ajax_choose_payment_method' ) );
			add_action( 'wp_ajax_zcf_book_with_zencoins', array( __CLASS__, 'ajax_book_with_zencoins' ) );
			add_action( 'wp_ajax_zcf_add_recovery_product', array( __CLASS__, 'ajax_add_recovery_product' ) );
			add_action( 'wp_ajax_zcf_remove_recovery_products', array( __CLASS__, 'ajax_remove_recovery_products' ) );

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

			self::hydrate_checkout_customer_profile();

			$native_card_bootstrap = self::prepare_native_card_runtime_assets();

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
					'homeUrl'     => home_url( '/' ),
					'autoOpen'    => self::should_auto_open_popup(),
					'popupOwnsRoute' => self::is_popup_owned_route(),
					'myAccountUrl' => self::dependencies_loaded() ? wc_get_page_permalink( 'myaccount' ) : '',
					'isLoggedIn'   => is_user_logged_in(),
					'customer'    => self::get_checkout_customer_data(),
					'gatewayRuntime' => self::get_gateway_runtime_context(),
					'nativeCardBootstrap' => $native_card_bootstrap,
					'i18n'        => array(
						'loading' => __( 'Updating...', 'zen-checkout-flow' ),
						'error'   => __( 'Something went wrong. Please try again.', 'zen-checkout-flow' ),
						'processing' => __( 'Processing payment...', 'zen-checkout-flow' ),
					),
				)
			);

			if ( ! is_admin() && self::dependencies_loaded() ) {
				if ( wp_script_is( 'wc-checkout-block-frontend', 'registered' ) ) {
					wp_enqueue_script( 'wc-checkout-block-frontend' );
				}

				if ( wp_style_is( 'wc-blocks-style', 'registered' ) ) {
					wp_enqueue_style( 'wc-blocks-style' );
				}

				if ( wp_style_is( 'wc-blocks-packages-style', 'registered' ) ) {
					wp_enqueue_style( 'wc-blocks-packages-style' );
				}

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

			if ( self::is_native_checkout_page() ) {
				return false;
			}

			$has_open_flag = isset( $_GET['zcf_open_checkout'] ) && '1' === wc_clean( wp_unslash( $_GET['zcf_open_checkout'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return ( function_exists( 'is_cart' ) && is_cart() )
				|| $has_open_flag
				|| self::get_mixed_recovery_result_from_request();
		}

		/**
		 * Whether the current frontend route should be visually owned by the popup.
		 *
		 * @return bool
		 */
		private static function is_popup_owned_route() {
			if ( ! self::dependencies_loaded() ) {
				return false;
			}

			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return true;
			}

			if ( self::get_mixed_recovery_result_from_request() ) {
				return true;
			}

			return isset( $_GET['zcf_open_checkout'] ) && '1' === wc_clean( wp_unslash( $_GET['zcf_open_checkout'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Whether we are on the native checkout page where Woo already owns the checkout runtime.
		 *
		 * @return bool
		 */
		private static function is_native_checkout_page() {
			if ( self::get_mixed_recovery_result_from_request() ) {
				return false;
			}

			return function_exists( 'is_checkout' )
				&& is_checkout()
				&& ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() );
		}

		/**
		 * Redirect direct access to the native checkout page into the popup-owned flow.
		 *
		 * Order-pay and order-received routes are left alone for now because the
		 * project still relies on Woo order context there.
		 *
		 * @return void
		 */
		public static function maybe_redirect_native_checkout_route() {
			if ( is_admin() || wp_doing_ajax() || ! self::dependencies_loaded() ) {
				return;
			}

			if ( ! self::is_native_checkout_page() ) {
				return;
			}

			if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
				return;
			}

			$target = wc_get_cart_url();

			if ( ! $target ) {
				return;
			}

			wp_safe_redirect(
				add_query_arg(
					'zcf_open_checkout',
					'1',
					remove_query_arg( 'zcf_open_checkout', $target )
				)
			);
			exit;
		}

		/**
		 * Add route-awareness classes for popup-owned checkout surfaces.
		 *
		 * @param array $classes Existing body classes.
		 * @return array
		 */
		public static function filter_body_class( $classes ) {
			if ( self::is_popup_owned_route() ) {
				$classes[] = 'zcf-owned-checkout-route';
			}

			if ( self::is_native_checkout_page() ) {
				$classes[] = 'zcf-native-checkout-route';
			}

			return $classes;
		}

		/**
		 * Render the plugin-owned popup mount point.
		 */
		public static function render_popup_root() {
			if ( is_admin() || ! self::dependencies_loaded() || self::is_native_checkout_page() ) {
				return;
			}

			?>
			<div id="zcf-popup" class="zcf-popup<?php echo self::is_popup_owned_route() ? ' zcf-popup--owned-route' : ''; ?>" aria-hidden="true">
				<div class="zcf-popup-backdrop" data-zcf-popup-close></div>
				<div class="zcf-popup-stage" data-zcf-popup-stage>
					<div class="zcf-popup-loading" data-zcf-popup-loading><?php esc_html_e( 'Loading checkout...', 'zen-checkout-flow' ); ?></div>
				</div>
			</div>
			<div class="zcf-native-host-stash" data-zcf-native-host-stash aria-hidden="true">
				<div class="zcf-native-host-stash__item" data-zcf-persistent-checkout-host>
					<?php echo self::render_checkout_block_host_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
					'html' => self::render_shell( self::get_ajax_current_url(), self::get_ajax_step() ),
					'step' => self::normalize_step( self::get_ajax_step() ),
				)
			);
		}

		/**
		 * Render an embeddable checkout shell.
		 *
		 * @return string
		 */
		private static function render_shell( $current_url = '', $step = 'auto' ) {
			ob_start();
			?>
			<div class="zcf-shell" data-zcf-checkout-flow>
				<?php echo self::render_frame( __( 'Buy now:', 'zen-checkout-flow' ), $current_url, $step ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
		private static function render_frame( $title, $current_url = '', $step = 'auto' ) {
			if ( ! is_user_logged_in() ) {
				return self::render_logged_out();
			}

			$recovery_result = self::get_mixed_recovery_result_from_request( $current_url );

			if ( $recovery_result ) {
				return self::render_mixed_recovery_result( $recovery_result );
			}

			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return self::render_empty_cart();
			}

			$context             = self::get_checkout_context();
			$step                = self::resolve_frame_step( $context, $step );
			$show_checkout_intro = 'payment' === $step;
			$is_cart_step        = in_array( $step, array( 'choose_plan', 'shortage_prompt' ), true );
			$show_back           = 'payment' === $step && ! empty( $context['has_booking_items'] );

			ob_start();
			?>
			<div class="zcf-modal" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Checkout', 'zen-checkout-flow' ); ?>" data-zcf-step="<?php echo esc_attr( $step ); ?>">
				<div class="zcf-topbar">
					<?php if ( $show_back ) : ?>
						<button type="button" class="zcf-icon-button zcf-back" data-zcf-back aria-label="<?php echo esc_attr__( 'Back', 'zen-checkout-flow' ); ?>">
							<span class="zcf-back__glyph" aria-hidden="true">&larr;</span>
						</button>
					<?php else : ?>
						<span class="zcf-topbar-spacer" aria-hidden="true"></span>
					<?php endif; ?>
					<button type="button" class="zcf-icon-button zcf-close" data-zcf-close aria-label="<?php echo esc_attr__( 'Close', 'zen-checkout-flow' ); ?>"></button>
				</div>

				<div class="zcf-grid">
					<?php if ( $is_cart_step ) : ?>
						<section class="zcf-left zcf-left--cart-step">
							<?php echo self::render_customer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div class="zcf-payment-panel zcf-payment-panel--chooser" data-zcf-payment-panel>
								<?php echo self::render_payment_panel( $step ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</section>

						<section class="zcf-right zcf-right--cart-step">
							<div class="zcf-cart-items" data-zcf-cart-items>
								<?php echo self::render_cart_items( 'booking_only' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
							<div class="zcf-checkout-result" data-zcf-checkout-result aria-live="polite"></div>
						</section>
					<?php else : ?>
						<section class="zcf-left">
							<?php echo self::render_customer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div class="zcf-cart-items" data-zcf-cart-items>
								<?php echo self::render_cart_items(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</section>

						<section class="zcf-right">
							<?php if ( $show_checkout_intro ) : ?>
								<h2><?php echo esc_html( $title ); ?></h2>
								<p class="zcf-muted"><?php esc_html_e( 'A confirmation of your purchase will be sent to you by email.', 'zen-checkout-flow' ); ?></p>
							<?php endif; ?>

							<div class="zcf-payment-panel" data-zcf-payment-panel>
								<?php echo self::render_payment_panel( $step ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>

							<?php if ( 'zencoin_booking' === ( isset( $context['mode'] ) ? $context['mode'] : '' ) ) : ?>
								<p class="zcf-terms">
									<?php esc_html_e( 'By completing this purchase, you agree to the', 'zen-checkout-flow' ); ?>
									<a href="<?php echo esc_url( wc_get_page_permalink( 'terms' ) ); ?>"><?php esc_html_e( 'terms and conditions.', 'zen-checkout-flow' ); ?></a>
								</p>

								<div data-zcf-primary-action>
									<?php echo self::render_primary_action(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php endif; ?>
							<div class="zcf-checkout-result" data-zcf-checkout-result aria-live="polite"></div>
						</section>
					<?php endif; ?>
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
			if ( ! self::dependencies_loaded() || ! is_user_logged_in() ) {
				return array();
			}

			return self::resolve_checkout_customer_profile();
		}

		/**
		 * Resolve the checkout profile using user meta first, then Woo customer/session values.
		 *
		 * @return array
		 */
		private static function resolve_checkout_customer_profile() {
			$user      = wp_get_current_user();
			$user_id   = (int) $user->ID;
			$customer  = ( self::dependencies_loaded() && WC()->customer ) ? WC()->customer : null;
			$get_value = static function( $meta_key, $customer_getter = '' ) use ( $user_id, $customer ) {
				$user_meta = $user_id ? (string) get_user_meta( $user_id, $meta_key, true ) : '';

				if ( '' !== trim( $user_meta ) ) {
					return trim( $user_meta );
				}

				if ( $customer && $customer_getter && is_callable( array( $customer, $customer_getter ) ) ) {
					$customer_value = (string) $customer->{$customer_getter}();

					if ( '' !== trim( $customer_value ) ) {
						return trim( $customer_value );
					}
				}

				return '';
			};

			$country = self::normalize_country_code( $get_value( 'billing_country', 'get_billing_country' ) );

			if ( ! $country && WC()->countries ) {
				$country = WC()->countries->get_base_country();
			}

			return array(
				'billing_first_name' => $get_value( 'billing_first_name', 'get_billing_first_name' ) ?: $user->first_name,
				'billing_last_name'  => $get_value( 'billing_last_name', 'get_billing_last_name' ) ?: $user->last_name,
				'billing_company'    => $get_value( 'billing_company', 'get_billing_company' ),
				'billing_country'    => $country,
				'billing_address_1'  => $get_value( 'billing_address_1', 'get_billing_address_1' ),
				'billing_address_2'  => $get_value( 'billing_address_2', 'get_billing_address_2' ),
				'billing_city'       => $get_value( 'billing_city', 'get_billing_city' ),
				'billing_state'      => $get_value( 'billing_state', 'get_billing_state' ),
				'billing_postcode'   => $get_value( 'billing_postcode', 'get_billing_postcode' ),
				'billing_phone'      => $get_value( 'billing_phone', 'get_billing_phone' ),
				'billing_email'      => $get_value( 'billing_email', 'get_billing_email' ) ?: $user->user_email,
			);
		}

		/**
		 * Normalize a country value to a WooCommerce country code when possible.
		 *
		 * @param string $country Country code or country name.
		 * @return string
		 */
		private static function normalize_country_code( $country ) {
			$country = trim( (string) $country );

			if ( '' === $country ) {
				return '';
			}

			if ( 2 === strlen( $country ) ) {
				return strtoupper( $country );
			}

			if ( ! WC()->countries ) {
				return $country;
			}

			$countries = WC()->countries->get_countries();

			foreach ( $countries as $country_code => $country_label ) {
				if ( 0 === strcasecmp( $country_label, $country ) ) {
					return (string) $country_code;
				}
			}

			return $country;
		}

		/**
		 * Hydrate the Woo customer/session object from the resolved profile so the
		 * checkout block receives stable values without relying on stale session data.
		 *
		 * @return void
		 */
		private static function hydrate_checkout_customer_profile() {
			if ( ! self::dependencies_loaded() || ! is_user_logged_in() || ! WC()->customer ) {
				return;
			}

			$profile = self::resolve_checkout_customer_profile();

			WC()->customer->set_billing_first_name( $profile['billing_first_name'] );
			WC()->customer->set_billing_last_name( $profile['billing_last_name'] );
			WC()->customer->set_billing_company( $profile['billing_company'] );
			WC()->customer->set_billing_country( $profile['billing_country'] );
			WC()->customer->set_billing_address_1( $profile['billing_address_1'] );
			WC()->customer->set_billing_address_2( $profile['billing_address_2'] );
			WC()->customer->set_billing_city( $profile['billing_city'] );
			WC()->customer->set_billing_state( $profile['billing_state'] );
			WC()->customer->set_billing_postcode( $profile['billing_postcode'] );
			WC()->customer->set_billing_phone( $profile['billing_phone'] );
			WC()->customer->set_billing_email( $profile['billing_email'] );

			if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
				WC()->customer->set_shipping_first_name( $profile['billing_first_name'] );
				WC()->customer->set_shipping_last_name( $profile['billing_last_name'] );
				WC()->customer->set_shipping_company( $profile['billing_company'] );
				WC()->customer->set_shipping_country( $profile['billing_country'] );
				WC()->customer->set_shipping_address_1( $profile['billing_address_1'] );
				WC()->customer->set_shipping_address_2( $profile['billing_address_2'] );
				WC()->customer->set_shipping_city( $profile['billing_city'] );
				WC()->customer->set_shipping_state( $profile['billing_state'] );
				WC()->customer->set_shipping_postcode( $profile['billing_postcode'] );
			}

			WC()->customer->save();
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
					<button type="button" class="zcf-button zcf-button-primary" data-zcf-login><?php esc_html_e( 'Log in', 'zen-checkout-flow' ); ?></button>
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
		 * Render a mixed-recovery completion/result state.
		 *
		 * @param array $result Result data.
		 * @return string
		 */
		private static function render_mixed_recovery_result( $result ) {
			$status  = isset( $result['status'] ) ? sanitize_key( $result['status'] ) : '';
			$config  = self::get_mixed_recovery_result_config( $status, $result );
			$classes = array( 'zcf-modal', 'zcf-result-modal', 'zcf-result-' . sanitize_html_class( $status ? $status : 'unknown' ) );

			if ( 'completed' === $status ) {
				return self::render_mixed_recovery_success_result( $result, $config );
			}

			ob_start();
			?>
			<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $config['title'] ); ?>">
				<div class="zcf-topbar">
					<button type="button" class="zcf-icon-button zcf-back" data-zcf-back aria-label="<?php echo esc_attr__( 'Back', 'zen-checkout-flow' ); ?>"></button>
					<button type="button" class="zcf-icon-button zcf-close" data-zcf-close aria-label="<?php echo esc_attr__( 'Close', 'zen-checkout-flow' ); ?>"></button>
				</div>

				<div class="zcf-result-panel">
					<h2><?php echo esc_html( $config['title'] ); ?></h2>
					<div class="zcf-result-mark" aria-hidden="true"></div>
					<p><?php echo esc_html( $config['message'] ); ?></p>
					<div class="zcf-result-actions">
						<?php echo self::render_result_button( $config['primary'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! empty( $config['secondary']['label'] ) ) : ?>
							<?php echo self::render_result_button( $config['secondary'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render the successful purchase-and-booking result in checkout layout.
		 *
		 * @param array $result Result data.
		 * @param array $config Result copy/actions.
		 * @return string
		 */
		private static function render_mixed_recovery_success_result( $result, $config ) {
			$order = ! empty( $result['order_id'] ) ? wc_get_order( absint( $result['order_id'] ) ) : false;

			ob_start();
			?>
			<div class="zcf-modal zcf-success-modal" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $config['title'] ); ?>">
				<div class="zcf-topbar">
					<button type="button" class="zcf-icon-button zcf-back" data-zcf-back aria-label="<?php echo esc_attr__( 'Back', 'zen-checkout-flow' ); ?>"></button>
					<button type="button" class="zcf-icon-button zcf-close" data-zcf-close aria-label="<?php echo esc_attr__( 'Close', 'zen-checkout-flow' ); ?>"></button>
				</div>

				<div class="zcf-grid">
					<section class="zcf-left">
						<?php echo self::render_customer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="zcf-cart-items">
							<?php echo $order ? self::render_order_result_items( $order ) : self::render_cart_items(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</section>

					<section class="zcf-right">
						<div class="zcf-success-panel">
							<h2><?php echo esc_html( $config['title'] ); ?></h2>
							<p><?php echo esc_html( $config['message'] ); ?></p>
							<div class="zcf-success-actions">
								<?php echo self::render_result_button( $config['secondary'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo self::render_result_button( $config['primary'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</div>
					</section>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Get copy/actions for a mixed-recovery result status.
		 *
		 * @param string $status Result status.
		 * @param array  $result Result data.
		 * @return array
		 */
		private static function get_mixed_recovery_result_config( $status, $result ) {
			$message = ! empty( $result['user_message'] ) ? $result['user_message'] : '';

			$configs = array(
				'completed' => array(
					'title'     => __( 'Congratulations!', 'zen-checkout-flow' ),
					'message'   => __( 'Your purchase is confirmed. You will shortly receive a confirmation email with all the details. You can already start booking and use your Zencoins!', 'zen-checkout-flow' ),
					'primary'   => array(
						'label'  => __( 'To Schedule', 'zen-checkout-flow' ),
						'action' => 'schedule',
					),
					'secondary' => array(
						'label'  => __( 'Profile', 'zen-checkout-flow' ),
						'action' => 'profile',
						'url'    => self::dependencies_loaded() ? wc_get_page_permalink( 'myaccount' ) : '',
					),
				),
				'payment_failed' => array(
					'title'     => __( 'Something went wrong...', 'zen-checkout-flow' ),
					'message'   => $message ? $message : __( 'Please try again or use a different payment method.', 'zen-checkout-flow' ),
					'primary'   => array(
						'label'  => __( 'Try again', 'zen-checkout-flow' ),
						'action' => 'retry',
					),
					'secondary' => array(
						'label'  => __( 'Cancel', 'zen-checkout-flow' ),
						'action' => 'close',
					),
				),
				'booking_full' => array(
					'title'     => __( 'Sorry! This class is already full', 'zen-checkout-flow' ),
					'message'   => $message ? $message : __( 'Your payment was completed, but this class filled up at the last moment. Your Zencoins remain in your wallet so you can schedule another class.', 'zen-checkout-flow' ),
					'primary'   => array(
						'label'  => __( 'Schedule', 'zen-checkout-flow' ),
						'action' => 'schedule',
					),
					'secondary' => array(
						'label'  => __( 'Cancel', 'zen-checkout-flow' ),
						'action' => 'close',
					),
				),
				'booking_failed' => array(
					'title'     => __( 'Booking failed!', 'zen-checkout-flow' ),
					'message'   => $message ? $message : __( 'A technical issue prevented booking completion. Your Zencoins were credited to your account and you can try booking again at any time.', 'zen-checkout-flow' ),
					'primary'   => array(
						'label'  => __( 'Schedule', 'zen-checkout-flow' ),
						'action' => 'schedule',
					),
					'secondary' => array(
						'label'  => __( 'Profile', 'zen-checkout-flow' ),
						'action' => 'profile',
						'url'    => self::dependencies_loaded() ? wc_get_page_permalink( 'myaccount' ) : '',
					),
				),
			);

			if ( isset( $configs[ $status ] ) ) {
				return $configs[ $status ];
			}

			return array(
				'title'     => __( 'Booking update', 'zen-checkout-flow' ),
				'message'   => $message ? $message : __( 'Your checkout result is ready. Please review your account or schedule another class.', 'zen-checkout-flow' ),
				'primary'   => array(
					'label'  => __( 'Schedule', 'zen-checkout-flow' ),
					'action' => 'schedule',
				),
				'secondary' => array(
					'label'  => __( 'Profile', 'zen-checkout-flow' ),
					'action' => 'profile',
					'url'    => self::dependencies_loaded() ? wc_get_page_permalink( 'myaccount' ) : '',
				),
			);
		}

		/**
		 * Render a result action button.
		 *
		 * @param array $button       Button config.
		 * @param bool  $is_secondary Whether secondary style should be used.
		 * @return string
		 */
		private static function render_result_button( $button, $is_secondary = false ) {
			$label  = isset( $button['label'] ) ? (string) $button['label'] : '';
			$action = isset( $button['action'] ) ? sanitize_key( $button['action'] ) : 'close';
			$url    = isset( $button['url'] ) ? esc_url( $button['url'] ) : '';

			if ( '' === $label ) {
				return '';
			}

			$class = $is_secondary ? 'zcf-result-button is-secondary' : 'zcf-result-button is-primary';

			if ( 'profile' === $action && $url ) {
				return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}

			return '<button type="button" class="' . esc_attr( $class ) . '" data-zcf-result-action="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button>';
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
		private static function render_cart_items( $scope = 'all' ) {
			$scope = in_array( $scope, array( 'all', 'booking_only', 'purchase_only' ), true ) ? $scope : 'all';

			$booking_items  = array();
			$purchase_items = array();

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( self::is_booking_cart_item( $cart_item ) ) {
					$booking_items[ $cart_item_key ] = $cart_item;
				} else {
					$purchase_items[ $cart_item_key ] = $cart_item;
				}
			}

			ob_start();

			if ( ! empty( $booking_items ) && 'purchase_only' !== $scope ) {
				?>
				<div class="zcf-cart-group">
					<div class="zcf-section-title"><?php esc_html_e( 'Selected class / service:', 'zen-checkout-flow' ); ?></div>
					<div class="zcf-cart-group__items">
						<?php foreach ( $booking_items as $cart_item_key => $cart_item ) : ?>
							<?php echo self::render_booking_cart_item_card( $cart_item_key, $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
			}

			if ( ! empty( $purchase_items ) && 'booking_only' !== $scope ) {
				?>
				<div class="zcf-cart-group">
					<div class="zcf-section-title">
						<?php echo ! empty( $booking_items ) ? esc_html__( 'Your purchase:', 'zen-checkout-flow' ) : esc_html__( 'Your product:', 'zen-checkout-flow' ); ?>
					</div>
					<div class="zcf-cart-group__items">
						<?php foreach ( $purchase_items as $cart_item_key => $cart_item ) : ?>
							<?php echo self::render_cart_item_card( $cart_item_key, $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
			}

			return ob_get_clean();
		}

		/**
		 * Render order items for a post-checkout result state.
		 *
		 * @param WC_Order $order Order object.
		 * @return string
		 */
		private static function render_order_result_items( $order ) {
			$booking_items  = array();
			$purchase_items = array();

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				if ( self::is_booking_order_item( $item_id, $item ) ) {
					$booking_items[ $item_id ] = $item;
				} else {
					$purchase_items[ $item_id ] = $item;
				}
			}

			ob_start();

			if ( ! empty( $booking_items ) ) {
				?>
				<div class="zcf-cart-group">
					<div class="zcf-section-title"><?php esc_html_e( 'Selected class / service:', 'zen-checkout-flow' ); ?></div>
					<div class="zcf-cart-group__items">
						<?php foreach ( $booking_items as $item_id => $item ) : ?>
							<?php echo self::render_order_item_card( $item_id, $item, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
			}

			if ( ! empty( $purchase_items ) ) {
				?>
				<div class="zcf-cart-group">
					<div class="zcf-section-title">
						<?php echo ! empty( $booking_items ) ? esc_html__( 'Your purchase:', 'zen-checkout-flow' ) : esc_html__( 'Your product:', 'zen-checkout-flow' ); ?>
					</div>
					<div class="zcf-cart-group__items">
						<?php foreach ( $purchase_items as $item_id => $item ) : ?>
							<?php echo self::render_order_item_card( $item_id, $item, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
			}

			return ob_get_clean();
		}

		/**
		 * Determine whether an order item represents a booking.
		 *
		 * @param int                   $item_id Order item ID.
		 * @param WC_Order_Item_Product $item    Order item.
		 * @return bool
		 */
		private static function is_booking_order_item( $item_id, $item ) {
			if ( $item && $item->get_meta( '_cbb_coin_item_cost', true ) ) {
				return true;
			}

			if ( class_exists( 'WC_Booking_Data_Store' ) && WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id ) ) {
				return true;
			}

			$product = $item ? $item->get_product() : false;

			return $product && is_callable( array( $product, 'is_type' ) ) && $product->is_type( array( 'booking', 'bookable' ) );
		}

		/**
		 * Render a post-checkout order item card.
		 *
		 * @param int                   $item_id Order item ID.
		 * @param WC_Order_Item_Product $item    Order item.
		 * @param WC_Order              $order   Order object.
		 * @return string
		 */
		private static function render_order_item_card( $item_id, $item, $order ) {
			$product  = $item->get_product();
			$quantity = max( 1, (int) $item->get_quantity() );
			$subtotal = $order->get_formatted_line_subtotal( $item );
			$coin_cost = $item->get_meta( '_cbb_coin_item_cost', true );

			ob_start();
			?>
			<article class="zcf-product-card zcf-order-card" data-order-item-id="<?php echo esc_attr( $item_id ); ?>">
				<div class="zcf-product-main">
					<div>
						<h3><?php echo esc_html( $item->get_name() ); ?></h3>
						<div class="zcf-product-meta">
							<?php
							echo esc_html(
								$coin_cost
									? sprintf( __( 'Zencoin booking cost: %s ZC', 'zen-checkout-flow' ), wc_format_decimal( $coin_cost, 2 ) )
									: sprintf( __( 'Quantity: %d', 'zen-checkout-flow' ), $quantity )
							);
							?>
						</div>
					</div>
					<div class="zcf-product-price">
						<strong><?php echo wp_kses_post( $subtotal ); ?></strong>
						<?php echo $product ? self::render_price_suffix( $product ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>

				<?php if ( self::is_booking_order_item( $item_id, $item ) ) : ?>
					<div class="zcf-order-card__status"><?php esc_html_e( 'Booked', 'zen-checkout-flow' ); ?></div>
				<?php else : ?>
					<details class="zcf-more">
						<summary><?php esc_html_e( 'more information', 'zen-checkout-flow' ); ?></summary>
						<div class="zcf-more-body">
							<?php echo wp_kses_post( wc_display_item_meta( $item, array( 'echo' => false ) ) ); ?>
						</div>
					</details>
				<?php endif; ?>
			</article>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render a single cart item card.
		 *
		 * @param string $cart_item_key Cart item key.
		 * @param array  $cart_item     Cart item.
		 * @return string
		 */
		private static function render_cart_item_card( $cart_item_key, $cart_item ) {
			$product = $cart_item['data'];

			if ( ! $product || ! $product->exists() ) {
				return '';
			}

			$quantity       = max( 1, (int) $cart_item['quantity'] );
			$zencoin_grant  = self::get_product_zencoin_grant_label( $product );
			$validity_label = self::get_product_zencoin_validity_label( $product );

			ob_start();
			?>
			<article class="zcf-product-card" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>">
				<div class="zcf-product-main">
					<div>
						<h3><?php echo esc_html( $product->get_name() ); ?></h3>
						<?php echo self::render_product_meta( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $validity_label ) : ?>
							<div class="zcf-product-validity"><?php echo esc_html( $validity_label ); ?></div>
						<?php endif; ?>
					</div>
					<div class="zcf-product-price">
						<strong><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $quantity ) ); ?></strong>
						<?php if ( '' !== $zencoin_grant ) : ?>
							<div class="zcf-product-zencoins">
								<span><?php esc_html_e( 'ZENCOINS:', 'zen-checkout-flow' ); ?></span>
								<?php echo self::render_zencoin_badge( $zencoin_grant ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php else : ?>
							<?php echo self::render_price_suffix( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</div>

				<button type="button" class="zcf-product-cta zcf-product-cta--remove" data-zcf-remove-cart-item="<?php echo esc_attr( $cart_item_key ); ?>">
					<?php esc_html_e( 'Remove', 'zen-checkout-flow' ); ?>
				</button>

				<details class="zcf-more">
					<summary><?php esc_html_e( 'more information', 'zen-checkout-flow' ); ?></summary>
					<div class="zcf-more-body">
						<?php echo wp_kses_post( wc_get_formatted_cart_item_data( $cart_item ) ); ?>
					</div>
				</details>
			</article>
			<?php

			return ob_get_clean();
		}

		/**
		 * Render a booking-focused cart item card for the cart step.
		 *
		 * @param string $cart_item_key Cart item key.
		 * @param array  $cart_item     Cart item.
		 * @return string
		 */
		private static function render_booking_cart_item_card( $cart_item_key, $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

			if ( ! $product || ! $product->exists() ) {
				return '';
			}

			$summary = self::get_booking_cart_item_summary( $cart_item );

			ob_start();
			?>
			<article class="zcf-product-card zcf-product-card--booking" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>">
				<div class="zcf-booking-card__head">
					<h3><?php echo esc_html( $product->get_name() ); ?></h3>
					<?php if ( '' !== $summary['zencoins'] ) : ?>
						<?php echo self::render_zencoin_badge( $summary['zencoins'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</div>

				<div class="zcf-booking-card__meta">
					<?php if ( $summary['date'] ) : ?>
						<div class="zcf-booking-card__row"><?php echo self::render_booking_meta_icon( 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $summary['date'] ); ?></span></div>
					<?php endif; ?>
					<?php if ( $summary['timeslot'] ) : ?>
						<div class="zcf-booking-card__row"><?php echo self::render_booking_meta_icon( 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $summary['timeslot'] ); ?></span></div>
					<?php endif; ?>
					<?php if ( $summary['space'] ) : ?>
						<div class="zcf-booking-card__row"><?php echo self::render_booking_meta_icon( 'space' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $summary['space'] ); ?></span></div>
					<?php endif; ?>
					<?php if ( $summary['type'] ) : ?>
						<div class="zcf-booking-card__row"><?php echo self::render_booking_meta_icon( 'type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $summary['type'] ); ?></span></div>
					<?php endif; ?>
					<?php if ( $summary['instructor'] ) : ?>
						<div class="zcf-booking-card__instructor"><?php echo esc_html( $summary['instructor'] ); ?></div>
					<?php endif; ?>
				</div>
			</article>
			<?php

			return ob_get_clean();
		}

		/**
		 * Determine whether a cart item is a booking/class-style item.
		 *
		 * @param array $cart_item Cart item.
		 * @return bool
		 */
		private static function is_booking_cart_item( $cart_item ) {
			if ( ! empty( $cart_item['booking'] ) ) {
				return true;
			}

			if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
				return false;
			}

			return is_callable( array( $cart_item['data'], 'is_type' ) ) && $cart_item['data']->is_type( array( 'booking', 'bookable' ) );
		}

		/**
		 * Build a friendly booking summary for cart step rendering.
		 *
		 * @param array $cart_item Cart item.
		 * @return array
		 */
		private static function get_booking_cart_item_summary( $cart_item ) {
			$product     = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			$booking     = isset( $cart_item['booking'] ) ? $cart_item['booking'] : null;
			$start       = 0;
			$end         = 0;
			$resource_id = 0;

			if ( is_array( $booking ) && ! empty( $booking['_booking_id'] ) && function_exists( 'get_wc_booking' ) ) {
				$booking_object = get_wc_booking( absint( $booking['_booking_id'] ) );

				if ( $booking_object ) {
					$booking = $booking_object;
				}
			}

			if ( is_object( $booking ) ) {
				$start       = method_exists( $booking, 'get_start' ) ? (int) $booking->get_start( 'edit' ) : 0;
				$end         = method_exists( $booking, 'get_end' ) ? (int) $booking->get_end( 'edit' ) : 0;
				$resource_id = method_exists( $booking, 'get_resource_id' ) ? (int) $booking->get_resource_id( 'edit' ) : 0;
			} elseif ( is_array( $booking ) ) {
				$start       = ! empty( $booking['_start_date'] ) ? strtotime( (string) $booking['_start_date'] ) : 0;
				$end         = ! empty( $booking['_end_date'] ) ? strtotime( (string) $booking['_end_date'] ) : 0;
				$resource_id = ! empty( $booking['_resource_id'] ) ? absint( $booking['_resource_id'] ) : 0;
			}

			$date_format = function_exists( 'wc_bookings_date_format' ) ? wc_bookings_date_format() : get_option( 'date_format' );
			$time_format = function_exists( 'wc_bookings_time_format' ) ? wc_bookings_time_format() : get_option( 'time_format' );
			$date        = $start ? date_i18n( $date_format, $start ) : '';
			$time = '';

			if ( $start ) {
				$time = date_i18n( $time_format, $start );

				if ( $end && $end > $start ) {
					$time .= ' - ' . date_i18n( $time_format, $end );
					$duration_minutes = (int) round( ( $end - $start ) / MINUTE_IN_SECONDS );

					if ( $duration_minutes > 0 ) {
						$time .= ' (' . sprintf( _n( '%dmin', '%dmin', $duration_minutes, 'zen-checkout-flow' ), $duration_minutes ) . ')';
					}
				}
			}

			return array(
				'date'      => $date,
				'timeslot'  => $time,
				'space'     => $resource_id ? get_the_title( $resource_id ) : '',
				'type'      => self::get_booking_type_label( $product ),
				'instructor' => self::get_booking_instructor_label( $product ),
				'zencoins'  => self::get_booking_coin_cost_label( $product ),
			);
		}

		/**
		 * Get a booking type label from the most relevant assigned term.
		 *
		 * @param WC_Product|false $product Product.
		 * @return string
		 */
		private static function get_booking_type_label( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return '';
			}

			$taxonomies = array( 'space_type', 'experience_category', 'activity_type', 'product_cat' );

			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $product->get_id(), $taxonomy );

				if ( empty( $terms ) || is_wp_error( $terms ) ) {
					continue;
				}

				$term = reset( $terms );

				if ( $term && ! empty( $term->name ) ) {
					return (string) $term->name;
				}
			}

			return '';
		}

		/**
		 * Get a booking coin cost label for the circular badge.
		 *
		 * @param WC_Product|false $product Product.
		 * @return string
		 */
		private static function get_booking_coin_cost_label( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return '';
			}

			$product_id = $product->get_id();
			$cost       = (float) get_post_meta( $product_id, '_cbb_booking_coin_cost', true );

			if ( $cost <= 0 && $product->is_type( 'variation' ) ) {
				$cost = (float) get_post_meta( $product->get_parent_id(), '_cbb_booking_coin_cost', true );
			}

			return $cost > 0 ? wc_format_decimal( $cost, 0 ) : '';
		}

		/**
		 * Get instructor/teacher label used by booking cards.
		 *
		 * @param WC_Product|false $product Product.
		 * @return string
		 */
		private static function get_booking_instructor_label( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return '';
			}

			foreach ( array( '_zen_instructor', 'zen_instructor', '_instructor', 'instructor' ) as $meta_key ) {
				$value = trim( (string) get_post_meta( $product->get_id(), $meta_key, true ) );

				if ( '' !== $value ) {
					return $value;
				}
			}

			return '';
		}

		/**
		 * Render a Zencoin badge only when a coin amount is available.
		 *
		 * @param string $value       Coin amount.
		 * @return string
		 */
		private static function render_zencoin_badge( $value ) {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				return '';
			}

			return '<span class="zen-coin-global zen-coin-global--replaced" aria-hidden="true"><span class="zen-coin-global__ring"></span><span class="zen-coin-global__value">' . esc_html( $value ) . '</span></span>';
		}

		/**
		 * Render booking meta icons copied from zen-bookpro.
		 *
		 * @param string $icon Icon key.
		 * @return string
		 */
		private static function render_booking_meta_icon( $icon ) {
			$icons = array(
				'date'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v2H2V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm15 9v8a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-8h20Z"/></svg>',
				'time'  => '<svg width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="12" r="11" fill="currentColor"/><path d="M12 7v5h5" stroke="#3f3f42" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
				'space' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5V3Zm2 0v18h7a2 2 0 0 0 2-2V8.414a2 2 0 0 0-.586-1.414l-3.414-3.414A2 2 0 0 0 15.586 3H12Z"/></svg>',
				'type'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5V3Zm2 0v18h7a2 2 0 0 0 2-2V8.414a2 2 0 0 0-.586-1.414l-3.414-3.414A2 2 0 0 0 15.586 3H12Z"/></svg>',
			);

			return isset( $icons[ $icon ] ) ? '<span class="zcf-booking-card__icon" aria-hidden="true">' . $icons[ $icon ] . '</span>' : '';
		}

		/**
		 * Get granted Zencoins for purchasable products.
		 *
		 * @param WC_Product $product Product.
		 * @return string
		 */
		private static function get_product_zencoin_grant_label( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return '';
			}

			$product_id = $product->get_id();
			$parent_id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product_id;
			$amount     = (float) get_post_meta( $product_id, '_cbb_zencoin_grant_amount', true );

			if ( $amount <= 0 ) {
				$amount = (float) get_post_meta( $product_id, '_cbb_coin_grant_amount', true );
			}

			if ( $amount <= 0 ) {
				$amount = (float) get_post_meta( $product_id, '_zen_coins', true );
			}

			if ( $amount <= 0 && $parent_id !== $product_id ) {
				$amount = (float) get_post_meta( $parent_id, '_cbb_zencoin_grant_amount', true );
			}

			return $amount > 0 ? wc_format_decimal( $amount, 0 ) : '';
		}

		/**
		 * Get validity text for Zencoin products.
		 *
		 * @param WC_Product $product Product.
		 * @return string
		 */
		private static function get_product_zencoin_validity_label( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return '';
			}

			$product_id = $product->get_id();
			$parent_id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product_id;
			$days       = (int) get_post_meta( $product_id, '_cbb_zencoin_validity_days', true );

			if ( $days <= 0 && $parent_id !== $product_id ) {
				$days = (int) get_post_meta( $parent_id, '_cbb_zencoin_validity_days', true );
			}

			if ( $days <= 0 ) {
				return '';
			}

			if ( 0 === $days % 30 ) {
				$months = max( 1, (int) round( $days / 30 ) );
				return sprintf( _n( 'Valid for %d Month', 'Valid for %d Months', $months, 'zen-checkout-flow' ), $months );
			}

			return sprintf( _n( 'Valid for %d Day', 'Valid for %d Days', $days, 'zen-checkout-flow' ), $days );
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

			return '<div class="zcf-product-meta">' . esc_html( implode( ' / ', $lines ) ) . '</div>';
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
		private static function render_payment_panel( $step = 'auto' ) {
			$context            = self::get_checkout_context();
			$mode               = isset( $context['mode'] ) ? $context['mode'] : 'money_purchase';
			$step               = self::resolve_frame_step( $context, $step );
			$is_cart_step       = in_array( $step, array( 'choose_plan', 'shortage_prompt' ), true );

			ob_start();
			?>
			<?php if ( self::should_render_debug() ) : ?>
				<?php echo self::render_checkout_context_debug(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<?php if ( $is_cart_step && 'insufficient_prompt' === $mode ) : ?>
				<?php echo self::render_insufficient_zencoin_prompt( $context, $step ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php
				return ob_get_clean();
			endif;
			?>
			<div class="zcf-panel-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ) ) ); ?></div>

			<div class="zcf-summary">
				<strong><?php esc_html_e( 'Order Summary', 'zen-checkout-flow' ); ?> <span><?php esc_html_e( '(incl. VAT):', 'zen-checkout-flow' ); ?></span></strong>
				<b><?php echo wp_kses_post( WC()->cart->get_total() ); ?></b>
			</div>

			<?php if ( in_array( $mode, array( 'money_purchase', 'mixed_recovery' ), true ) ) : ?>
				<?php if ( 'mixed_recovery' === $mode ) : ?>
					<?php echo self::render_mode_notice( __( 'Complete payment for the recovery product first. The booking will consume the granted Zencoins immediately after payment succeeds.', 'zen-checkout-flow' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<div class="zcf-payment-label"><?php esc_html_e( 'Payment method:', 'zen-checkout-flow' ); ?></div>
				<?php echo self::render_native_payment_runtime_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php elseif ( 'zencoin_booking' === $mode ) : ?>
				<?php echo self::render_mode_notice( sprintf( __( 'This booking is covered by your wallet. Required: %1$s ZC. Available: %2$s ZC.', 'zen-checkout-flow' ), wc_format_decimal( isset( $context['required_zencoins'] ) ? $context['required_zencoins'] : 0, 2 ), wc_format_decimal( isset( $context['available_zencoins'] ) ? $context['available_zencoins'] : 0, 2 ) ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="zcf-payment-label"><?php esc_html_e( 'Payment method:', 'zen-checkout-flow' ); ?></div>
				<div class="zcf-no-gateways"><?php esc_html_e( 'No payment gateway is needed when your booking is fully covered by Zencoins.', 'zen-checkout-flow' ); ?></div>
			<?php else : ?>
				<?php echo self::render_insufficient_zencoin_prompt( $context, $step ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render hidden billing fields from the stored customer profile.
		 *
		 * @param WC_Checkout $checkout Checkout object.
		 * @return string
		 */
		private static function render_hidden_customer_fields( $checkout ) {
			$fields        = $checkout->get_checkout_fields( 'billing' );
			$customer_data = self::get_checkout_customer_data();

			if ( empty( $fields ) || ! is_array( $fields ) ) {
				return '';
			}

			ob_start();

			foreach ( $fields as $key => $field ) {
				$value = $checkout->get_value( $key );

				if ( '' === $value && isset( $customer_data[ $key ] ) ) {
					$value = $customer_data[ $key ];
				}
				?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php
			}

			return ob_get_clean();
		}

		/**
		 * Whether developer debug information should be visible.
		 *
		 * @return bool
		 */
		private static function should_render_debug() {
			$debug_flag = isset( $_GET['zcf_debug'] ) ? wc_clean( wp_unslash( $_GET['zcf_debug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return current_user_can( 'manage_options' ) && '1' === $debug_flag;
		}

		/**
		 * Render the primary checkout action button for the current mode.
		 *
		 * @return string
		 */
		private static function render_primary_action() {
			$context = self::get_checkout_context();
			$mode    = isset( $context['mode'] ) ? $context['mode'] : 'money_purchase';

			ob_start();

			if ( in_array( $mode, array( 'money_purchase', 'mixed_recovery' ), true ) ) :
				return '';
			elseif ( 'zencoin_booking' === $mode ) :
				?>
				<button type="button" class="zcf-pay-button" data-zcf-book-zencoins>
					<?php esc_html_e( 'Book with Zencoins', 'zen-checkout-flow' ); ?>
				</button>
				<?php
			endif;

			return ob_get_clean();
		}

		/**
		 * Render the Figma-aligned insufficient-Zencoin recovery prompt.
		 *
		 * @param array $context Checkout context.
		 * @return string
		 */
		private static function render_insufficient_zencoin_prompt( $context, $step = 'auto' ) {
			$available = isset( $context['available_zencoins'] ) ? (float) $context['available_zencoins'] : 0.0;
			$missing   = isset( $context['missing_zencoins'] ) ? (float) $context['missing_zencoins'] : 0.0;
			$offers    = self::get_recovery_product_offers( $missing );
			$best      = self::get_best_recovery_offer( $offers, $missing );
			$is_zero   = 'choose_plan' === self::resolve_frame_step( $context, $step );

			ob_start();
			?>
			<div class="<?php echo esc_attr( $is_zero ? 'zcf-plan-chooser' : 'zcf-shortage-prompt' ); ?>">
				<?php if ( $is_zero ) : ?>
					<div class="zcf-plan-chooser__header">
						<h3><?php esc_html_e( 'Please choose the plan', 'zen-checkout-flow' ); ?></h3>
					</div>
				<?php else : ?>
					<div class="zcf-shortage-prompt__card">
						<h3><?php esc_html_e( 'Not enough Zencoins!', 'zen-checkout-flow' ); ?></h3>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: missing Zencoin amount. */
									__( 'You need %s more ZC to book this class. Would you like to buy now?', 'zen-checkout-flow' ),
									wc_format_decimal( $missing, 2 )
								)
							);
							?>
						</p>
						<div class="zcf-shortage-prompt__actions">
							<?php if ( $best ) : ?>
								<button type="button" class="zcf-result-button is-primary" data-zcf-add-recovery-product data-product-id="<?php echo esc_attr( $best['product_id'] ); ?>" data-variation-id="<?php echo esc_attr( $best['variation_id'] ); ?>">
									<?php echo esc_html( sprintf( __( 'Buy %s', 'zen-checkout-flow' ), $best['zencoins_label'] ) ); ?>
								</button>
							<?php else : ?>
								<a class="zcf-result-button is-primary" href="<?php echo esc_url( self::get_recovery_products_url() ); ?>">
									<?php esc_html_e( 'Add Zencoins', 'zen-checkout-flow' ); ?>
								</a>
							<?php endif; ?>
							<button type="button" class="zcf-result-button is-secondary" data-zcf-result-action="schedule">
								<?php esc_html_e( 'Cancel', 'zen-checkout-flow' ); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $is_zero || ! $best ) : ?>
					<?php echo self::render_recovery_offer_list( $offers ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render recovery product cards.
		 *
		 * @param array $offers Recovery offers.
		 * @return string
		 */
		private static function render_recovery_offer_list( $offers ) {
			if ( empty( $offers ) ) {
				return self::render_mode_notice( __( 'No Zencoin plans are available yet. Please contact the studio or try again later.', 'zen-checkout-flow' ), 'warning' );
			}

			ob_start();
			?>
			<div class="zcf-plan-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Zencoin plan categories', 'zen-checkout-flow' ); ?>">
				<button type="button" class="is-active" data-zcf-plan-tab="all"><?php esc_html_e( 'All', 'zen-checkout-flow' ); ?></button>
				<button type="button" data-zcf-plan-tab="drop_in"><?php esc_html_e( 'Drop-Ins', 'zen-checkout-flow' ); ?></button>
				<button type="button" data-zcf-plan-tab="package"><?php esc_html_e( 'Packages', 'zen-checkout-flow' ); ?></button>
				<button type="button" data-zcf-plan-tab="membership"><?php esc_html_e( 'Memberships', 'zen-checkout-flow' ); ?></button>
			</div>
			<div class="zcf-recovery-offers">
				<?php foreach ( $offers as $offer ) : ?>
					<article class="zcf-recovery-card" data-zcf-plan-type="<?php echo esc_attr( $offer['product_type'] ); ?>">
						<div class="zcf-recovery-card__head">
							<strong><?php echo esc_html( $offer['title'] ); ?></strong>
							<b><?php echo wp_kses_post( $offer['price_html'] ); ?></b>
						</div>
						<div class="zcf-recovery-card__subhead">
							<span><?php echo esc_html( $offer['eur_per_zencoin_label'] ); ?></span>
							<span class="zcf-recovery-card__zencoins">
								<?php esc_html_e( 'ZENCOINS:', 'zen-checkout-flow' ); ?>
								<?php echo self::render_zencoin_badge( $offer['zencoins_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						</div>
						<div class="zcf-recovery-card__validity"><?php echo esc_html( $offer['validity_label'] ); ?></div>
						<div class="zcf-recovery-card__divider"></div>
						<button type="button" class="zcf-product-cta" data-zcf-add-recovery-product data-product-id="<?php echo esc_attr( $offer['product_id'] ); ?>" data-variation-id="<?php echo esc_attr( $offer['variation_id'] ); ?>">
							<?php esc_html_e( 'To Payment', 'zen-checkout-flow' ); ?>
						</button>
						<details class="zcf-more">
							<summary><?php esc_html_e( 'more information', 'zen-checkout-flow' ); ?></summary>
							<div class="zcf-more-body">
								<?php echo wp_kses_post( $offer['description_html'] ); ?>
							</div>
						</details>
					</article>
				<?php endforeach; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Get recovery product offers from WooCommerce/CBB product meta.
		 *
		 * @param float $missing Missing ZC.
		 * @return array
		 */
		private static function get_recovery_product_offers( $missing = 0.0 ) {
			if ( ! self::dependencies_loaded() ) {
				return array();
			}

			$query_args = array(
				'status'     => 'publish',
				'limit'      => 24,
				'type'       => array( 'simple', 'subscription', 'variation', 'subscription_variation' ),
				'orderby'    => 'menu_order',
				'order'      => 'ASC',
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_cbb_zencoin_product_type',
						'value'   => array( 'package', 'drop_in' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_cbb_zencoin_grant_amount',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_cbb_coin_grant_amount',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			);

			$products = wc_get_products( apply_filters( 'zcf_recovery_product_query_args', $query_args, $missing ) );
			$offers   = array();

			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
					continue;
				}

				$offer = self::build_recovery_product_offer( $product );

				if ( $offer ) {
					$offers[] = $offer;
				}
			}

			usort(
				$offers,
				static function( $a, $b ) {
					if ( (float) $a['zencoins'] === (float) $b['zencoins'] ) {
						return strcmp( $a['title'], $b['title'] );
					}

					return (float) $a['zencoins'] <=> (float) $b['zencoins'];
				}
			);

			return (array) apply_filters( 'zcf_recovery_product_offers', $offers, $missing );
		}

		/**
		 * Build one recovery product offer.
		 *
		 * @param WC_Product $product Product.
		 * @return array
		 */
		private static function build_recovery_product_offer( $product ) {
			$product_id   = $product->get_id();
			$variation_id = $product->is_type( 'variation' ) ? $product_id : 0;
			$parent_id    = $variation_id ? $product->get_parent_id() : $product_id;
			$zencoins     = (float) get_post_meta( $product_id, '_cbb_zencoin_grant_amount', true );
			$product_type = self::get_recovery_offer_product_type( $product, $product_id, $parent_id );

			if ( $zencoins <= 0 ) {
				$zencoins = (float) get_post_meta( $product_id, '_cbb_coin_grant_amount', true );
			}

			if ( $zencoins <= 0 && $parent_id !== $product_id ) {
				$zencoins = (float) get_post_meta( $parent_id, '_cbb_zencoin_grant_amount', true );
			}

			if ( $zencoins <= 0 ) {
				return array();
			}

			if ( ! in_array( $product_type, array( 'package', 'drop_in', 'membership' ), true ) ) {
				return array();
			}

			return array(
				'product_id'     => $parent_id,
				'variation_id'   => $variation_id,
				'title'          => wp_strip_all_tags( $product->get_name() ),
				'price_html'     => $product->get_price_html(),
				'price_value'    => (float) wc_get_price_to_display( $product ),
				'product_type'   => $product_type,
				'zencoins'       => $zencoins,
				'zencoins_label' => wc_format_decimal( $zencoins, 0 ),
				'eur_per_zencoin_label' => self::format_offer_euro_per_zencoin_label( (float) wc_get_price_to_display( $product ), $zencoins ),
				'validity_label' => self::get_recovery_product_validity_label( $product_id, $parent_id ),
				'description_html' => wpautop( wp_kses_post( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ) ),
			);
		}

		/**
		 * Format the EUR-per-zencoin helper label for recovery cards.
		 *
		 * @param float $price    Product price.
		 * @param float $zencoins Granted Zencoins.
		 * @return string
		 */
		private static function format_offer_euro_per_zencoin_label( $price, $zencoins ) {
			if ( $price <= 0 || $zencoins <= 0 ) {
				return __( 'Plan available after purchase', 'zen-checkout-flow' );
			}

			$ratio = $price / $zencoins;

			return sprintf(
				/* translators: %s: euro value per zencoin. */
				__( '≈ %s / Zencoin', 'zen-checkout-flow' ),
				wp_strip_all_tags( wc_price( $ratio ) )
			);
		}

		/**
		 * Get recovery offer product type for UI tabs.
		 *
		 * @param WC_Product $product    Product.
		 * @param int        $product_id Product ID.
		 * @param int        $parent_id  Parent product ID.
		 * @return string
		 */
		private static function get_recovery_offer_product_type( $product, $product_id, $parent_id ) {
			$type = sanitize_key( (string) get_post_meta( $product_id, '_cbb_zencoin_product_type', true ) );

			if ( ( ! $type || 'none' === $type ) && $parent_id !== $product_id ) {
				$type = sanitize_key( (string) get_post_meta( $parent_id, '_cbb_zencoin_product_type', true ) );
			}

			if ( in_array( $type, array( 'package', 'drop_in', 'free_drop_in', 'gift_card', 'auto_top_up' ), true ) ) {
				return $type;
			}

			if ( is_callable( array( $product, 'is_type' ) ) && $product->is_type( array( 'subscription', 'variable-subscription', 'subscription_variation' ) ) ) {
				return 'membership';
			}

			return 'package';
		}

		/**
		 * Get a friendly validity label for a recovery product.
		 *
		 * @param int $product_id Product ID.
		 * @param int $parent_id  Parent product ID.
		 * @return string
		 */
		private static function get_recovery_product_validity_label( $product_id, $parent_id ) {
			$days = (int) get_post_meta( $product_id, '_cbb_zencoin_validity_days', true );

			if ( ! $days && $parent_id !== $product_id ) {
				$days = (int) get_post_meta( $parent_id, '_cbb_zencoin_validity_days', true );
			}

			if ( $days > 0 ) {
				$months = max( 1, (int) round( $days / 30 ) );

				return sprintf(
					/* translators: %s: number of months. */
					__( 'Valid for %s Month', 'zen-checkout-flow' ),
					$months
				);
			}

			return __( 'Valid after purchase', 'zen-checkout-flow' );
		}

		/**
		 * Pick the smallest offer that covers the missing ZC.
		 *
		 * @param array $offers  Recovery offers.
		 * @param float $missing Missing ZC.
		 * @return array
		 */
		private static function get_best_recovery_offer( $offers, $missing ) {
			foreach ( $offers as $offer ) {
				if ( (float) $offer['zencoins'] >= (float) $missing ) {
					return $offer;
				}
			}

			return array();
		}

		/**
		 * Get the fallback destination where customers can add recovery Zencoin products.
		 *
		 * @return string
		 */
		private static function get_recovery_products_url() {
			$url = self::dependencies_loaded() ? get_permalink( wc_get_page_id( 'shop' ) ) : '';

			if ( ! $url ) {
				$url = home_url( '/' );
			}

			/**
			 * Customize the destination used by the insufficient-Zencoin checkout prompt.
			 *
			 * @param string $url Default shop URL.
			 */
			return (string) apply_filters( 'zcf_recovery_products_url', $url );
		}

		/**
		 * Render the native popup payment runtime shell.
		 *
		 * This is the non-iframe base for the next implementation slice. We
		 * expose a dedicated mount point for the WooPayments card runtime and keep
		 * redirect-style gateways clearly separated.
		 *
		 * @return string
		 */
		private static function render_native_payment_runtime_shell() {
			ob_start();
			?>
			<div class="zcf-native-payment-runtime" data-zcf-native-payment-runtime>
				<div class="zcf-block-checkout-host" data-zcf-block-checkout-host>
					<div class="zcf-block-checkout-host__slot" data-zcf-block-checkout-slot>
						<div class="zcf-block-checkout-host__loading"><?php esc_html_e( 'Loading payment methods…', 'zen-checkout-flow' ); ?></div>
					</div>
				</div>
			</div>
			<?php

			return ob_get_clean();
		}

		/**
		 * Render a same-DOM checkout block host so payment methods run inside the
		 * real Woo Blocks provider tree while the popup only exposes the payment
		 * area visually.
		 *
		 * @return string
		 */
		private static function render_checkout_block_host_markup() {
			if ( ! function_exists( 'do_blocks' ) ) {
				return '';
			}

			return do_blocks( self::get_checkout_block_stub_markup() );
		}

		/**
		 * Get a minimal checkout block document that still gives Woo Blocks the
		 * full provider tree it expects.
		 *
		 * This mirrors WooCommerce's default checkout block structure closely
		 * enough for native payment runtimes, while the popup CSS hides the
		 * sections we do not want to expose.
		 *
		 * @return string
		 */
		private static function get_checkout_block_stub_markup() {
			return '<!-- wp:woocommerce/checkout -->
<div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"><!-- wp:woocommerce/checkout-fields-block -->
<div class="wp-block-woocommerce-checkout-fields-block"><!-- wp:woocommerce/checkout-express-payment-block -->
<div class="wp-block-woocommerce-checkout-express-payment-block"></div>
<!-- /wp:woocommerce/checkout-express-payment-block -->

<!-- wp:woocommerce/checkout-contact-information-block -->
<div class="wp-block-woocommerce-checkout-contact-information-block"></div>
<!-- /wp:woocommerce/checkout-contact-information-block -->

<!-- wp:woocommerce/checkout-shipping-method-block -->
<div class="wp-block-woocommerce-checkout-shipping-method-block"></div>
<!-- /wp:woocommerce/checkout-shipping-method-block -->

<!-- wp:woocommerce/checkout-pickup-options-block -->
<div class="wp-block-woocommerce-checkout-pickup-options-block"></div>
<!-- /wp:woocommerce/checkout-pickup-options-block -->

<!-- wp:woocommerce/checkout-shipping-address-block -->
<div class="wp-block-woocommerce-checkout-shipping-address-block"></div>
<!-- /wp:woocommerce/checkout-shipping-address-block -->

<!-- wp:woocommerce/checkout-billing-address-block -->
<div class="wp-block-woocommerce-checkout-billing-address-block"></div>
<!-- /wp:woocommerce/checkout-billing-address-block -->

<!-- wp:woocommerce/checkout-shipping-methods-block -->
<div class="wp-block-woocommerce-checkout-shipping-methods-block"></div>
<!-- /wp:woocommerce/checkout-shipping-methods-block -->

<!-- wp:woocommerce/checkout-payment-block -->
<div class="wp-block-woocommerce-checkout-payment-block"></div>
<!-- /wp:woocommerce/checkout-payment-block -->

<!-- wp:woocommerce/checkout-additional-information-block -->
<div class="wp-block-woocommerce-checkout-additional-information-block"></div>
<!-- /wp:woocommerce/checkout-additional-information-block -->

<!-- wp:woocommerce/checkout-order-note-block -->
<div class="wp-block-woocommerce-checkout-order-note-block"></div>
<!-- /wp:woocommerce/checkout-order-note-block -->

<!-- wp:woocommerce/checkout-terms-block -->
<div class="wp-block-woocommerce-checkout-terms-block"></div>
<!-- /wp:woocommerce/checkout-terms-block -->

<!-- wp:woocommerce/checkout-actions-block -->
<div class="wp-block-woocommerce-checkout-actions-block"></div>
<!-- /wp:woocommerce/checkout-actions-block --></div>
<!-- /wp:woocommerce/checkout-fields-block -->

<!-- wp:woocommerce/checkout-totals-block -->
<div class="wp-block-woocommerce-checkout-totals-block"><!-- wp:woocommerce/checkout-order-summary-block -->
<div class="wp-block-woocommerce-checkout-order-summary-block"><!-- wp:woocommerce/checkout-order-summary-cart-items-block -->
<div class="wp-block-woocommerce-checkout-order-summary-cart-items-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-cart-items-block -->

<!-- wp:woocommerce/checkout-order-summary-coupon-form-block -->
<div class="wp-block-woocommerce-checkout-order-summary-coupon-form-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-coupon-form-block -->

<!-- wp:woocommerce/checkout-order-summary-subtotal-block -->
<div class="wp-block-woocommerce-checkout-order-summary-subtotal-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-subtotal-block -->

<!-- wp:woocommerce/checkout-order-summary-fee-block -->
<div class="wp-block-woocommerce-checkout-order-summary-fee-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-fee-block -->

<!-- wp:woocommerce/checkout-order-summary-discount-block -->
<div class="wp-block-woocommerce-checkout-order-summary-discount-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-discount-block -->

<!-- wp:woocommerce/checkout-order-summary-shipping-block -->
<div class="wp-block-woocommerce-checkout-order-summary-shipping-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-shipping-block -->

<!-- wp:woocommerce/checkout-order-summary-taxes-block -->
<div class="wp-block-woocommerce-checkout-order-summary-taxes-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-taxes-block --></div>
<!-- /wp:woocommerce/checkout-order-summary-block --></div>
<!-- /wp:woocommerce/checkout-totals-block --></div>
<!-- /wp:woocommerce/checkout -->';
		}

		/**
		 * Get the gateway strategy registry for the popup checkout architecture.
		 *
		 * This is intentionally conservative for now. We are not changing runtime
		 * behavior yet; this only classifies gateways so we can verify the
		 * strategy map against the site's real payment methods.
		 *
		 * @return array
		 */
		private static function get_gateway_strategy_registry() {
			return array(
				'exact'  => array(
					'woocommerce_payments' => 'inline_sdk',
					'woocommerce_payments_amazon_pay' => 'redirect_offsite',
					'woo_wallet'           => 'wallet_internal',
					'wallet'               => 'wallet_internal',
					'cod'                  => 'classic_form',
					'bacs'                 => 'classic_form',
					'cheque'               => 'classic_form',
				),
				'prefix' => array(
					'woocommerce_payments_' => 'inline_sdk',
					'ppcp-'                 => 'redirect_offsite',
					'ppcp_'                 => 'redirect_offsite',
					'paypal_'               => 'redirect_offsite',
				),
				'contains' => array(
					'paypal' => 'redirect_offsite',
					'klarna' => 'inline_sdk',
					'stripe' => 'inline_sdk',
					'amazon' => 'redirect_offsite',
					'wallet' => 'wallet_internal',
				),
			);
		}

		/**
		 * Classify the strategy for a payment gateway.
		 *
		 * @param WC_Payment_Gateway|string $gateway Gateway object or gateway ID.
		 * @return string
		 */
		private static function get_gateway_strategy_for_gateway( $gateway ) {
			$gateway_id = is_object( $gateway ) && isset( $gateway->id ) ? (string) $gateway->id : (string) $gateway;
			$gateway_id = strtolower( $gateway_id );
			$registry   = self::get_gateway_strategy_registry();

			if ( isset( $registry['exact'][ $gateway_id ] ) ) {
				return $registry['exact'][ $gateway_id ];
			}

			foreach ( $registry['prefix'] as $prefix => $strategy ) {
				if ( 0 === strpos( $gateway_id, $prefix ) ) {
					return $strategy;
				}
			}

			foreach ( $registry['contains'] as $needle => $strategy ) {
				if ( false !== strpos( $gateway_id, $needle ) ) {
					return $strategy;
				}
			}

			return 'classic_unknown';
		}

		/**
		 * Get gateway strategy debug rows for currently available gateways.
		 *
		 * @return array
		 */
		private static function get_available_gateway_strategy_rows() {
			if ( ! self::dependencies_loaded() || ! WC()->payment_gateways() ) {
				return array();
			}

			$rows     = array();
			$gateways = WC()->payment_gateways()->get_available_payment_gateways();

			foreach ( $gateways as $gateway_id => $gateway ) {
				if ( self::should_hide_gateway_in_popup( $gateway ) ) {
					continue;
				}

				$rows[] = array(
					'id'       => (string) $gateway_id,
					'title'    => is_object( $gateway ) && isset( $gateway->title ) ? wp_strip_all_tags( (string) $gateway->title ) : '',
					'strategy' => self::get_gateway_strategy_for_gateway( $gateway ),
				);
			}

			return $rows;
		}

		/**
		 * Get structured runtime context for popup gateway orchestration.
		 *
		 * This is still informational in this phase. It gives us a stable
		 * contract between the server-side gateway discovery and the future
		 * native popup payment runtime.
		 *
		 * @return array
		 */
		private static function get_gateway_runtime_context() {
			$rows            = self::get_available_gateway_strategy_rows();
			$primary_gateway = ! empty( $rows ) ? $rows[0] : null;
			$bootstrap       = self::get_native_card_runtime_bootstrap_summary();
			$wcpay_card      = array(
				'available'   => false,
				'gateway_id'  => 'woocommerce_payments',
				'strategy'    => 'inline_sdk',
				'runtime'     => 'wcpay_blocks_checkout_provider',
				'ui'          => 'stripe_payment_element',
				'submission'  => 'store_api_checkout',
				'assets_ready'  => ! empty( $bootstrap['assets_enqueued'] ),
				'data_keys'     => ! empty( $bootstrap['data_keys'] ) ? $bootstrap['data_keys'] : array(),
				'script_handles'=> ! empty( $bootstrap['script_handles'] ) ? $bootstrap['script_handles'] : array(),
			);

			foreach ( $rows as $row ) {
				if ( 'woocommerce_payments' === $row['id'] ) {
					$wcpay_card['available'] = true;
					break;
				}
			}

			return array(
				'available_gateways'       => $rows,
				'primary_gateway_id'       => $primary_gateway ? $primary_gateway['id'] : '',
				'primary_gateway_strategy' => $primary_gateway ? $primary_gateway['strategy'] : '',
				'embedded_surface'         => false,
				'wcpay_card'               => $wcpay_card,
			);
		}

		/**
		 * Whether a gateway should be hidden from the popup payment experience.
		 *
		 * @param WC_Payment_Gateway|string $gateway Gateway object or ID.
		 * @return bool
		 */
		private static function should_hide_gateway_in_popup( $gateway ) {
			return 'wallet_internal' === self::get_gateway_strategy_for_gateway( $gateway );
		}

		/**
		 * Remove wallet-style gateways from the available list.
		 *
		 * For this project, wallet infrastructure is only used behind the Zencoin
		 * system and should never be shown as a money checkout option.
		 *
		 * @param array $gateways Available gateways.
		 * @return array
		 */
		public static function filter_popup_payment_gateways( $gateways ) {
			if ( empty( $gateways ) || ! is_array( $gateways ) ) {
				return $gateways;
			}

			foreach ( $gateways as $gateway_id => $gateway ) {
				if ( self::should_hide_gateway_in_popup( $gateway ) ) {
					unset( $gateways[ $gateway_id ] );
				}
			}

			return $gateways;
		}

		/**
		 * Render a mode-specific inline notice.
		 *
		 * @param string $message Notice message.
		 * @param string $type    Notice type.
		 * @return string
		 */
		private static function render_mode_notice( $message, $type = 'info' ) {
			$classes = array( 'zcf-mode-note' );

			if ( in_array( $type, array( 'info', 'success', 'warning' ), true ) ) {
				$classes[] = 'is-' . $type;
			}

			return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . esc_html( $message ) . '</div>';
		}

		/**
		 * Get the current URL passed by the popup AJAX renderer.
		 *
		 * @return string
		 */
		private static function get_ajax_current_url() {
			if ( empty( $_POST['current_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return '';
			}

			return esc_url_raw( wp_unslash( $_POST['current_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		/**
		 * Get requested popup step from AJAX.
		 *
		 * @return string
		 */
		private static function get_ajax_step() {
			if ( empty( $_POST['zcf_step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return 'auto';
			}

			return self::normalize_step( sanitize_key( wp_unslash( $_POST['zcf_step'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		/**
		 * Normalize a checkout popup step.
		 *
		 * @param string $step Requested step.
		 * @return string
		 */
		private static function normalize_step( $step ) {
			return in_array( $step, array( 'auto', 'choose_plan', 'shortage_prompt', 'payment' ), true ) ? $step : 'auto';
		}

		/**
		 * Resolve the actual frame step from context and requested step.
		 *
		 * @param array  $context Checkout context.
		 * @param string $step    Requested step.
		 * @return string
		 */
		private static function resolve_frame_step( $context, $step ) {
			$step = self::normalize_step( $step );
			$mode = isset( $context['mode'] ) ? $context['mode'] : 'money_purchase';

			if ( in_array( $step, array( 'choose_plan', 'shortage_prompt' ), true ) ) {
				return $step;
			}

			if ( in_array( $mode, array( 'money_purchase', 'mixed_recovery', 'zencoin_booking' ), true ) ) {
				return 'payment';
			}

			$available = isset( $context['available_zencoins'] ) ? (float) $context['available_zencoins'] : 0.0;

			return $available <= 0 ? 'choose_plan' : 'shortage_prompt';
		}

		/**
		 * Read a CBB mixed-recovery result from the order-received URL/order key.
		 *
		 * @param string $current_url Optional current frontend URL from AJAX.
		 * @return array|false
		 */
		private static function get_mixed_recovery_result_from_request( $current_url = '' ) {
			if ( ! self::dependencies_loaded() || ! is_user_logged_in() ) {
				return false;
			}

			$order = self::get_order_from_result_request( $current_url );

			if ( ! $order ) {
				return false;
			}

			$checkout_mode = (string) $order->get_meta( '_cbb_checkout_mode', true );

			if ( ! in_array( $checkout_mode, array( 'mixed_recovery', 'zencoin_booking' ), true ) ) {
				return false;
			}

			if ( (int) $order->get_customer_id() !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
				return false;
			}

			$status = sanitize_key( (string) $order->get_meta( '_cbb_mixed_recovery_status', true ) );

			if ( '' === $status && 'zencoin_booking' === $checkout_mode && $order->get_meta( '_cbb_coins_debited_transaction_id', true ) ) {
				$status = 'completed';
			}

			if ( ! in_array( $status, array( 'completed', 'payment_failed', 'booking_full', 'booking_failed' ), true ) ) {
				return false;
			}

			$context = $order->get_meta( '_cbb_mixed_recovery_context', true );
			$context = is_array( $context ) ? $context : array();

			return array(
				'order_id'      => $order->get_id(),
				'order_number'  => $order->get_order_number(),
				'status'        => $status,
				'user_message'  => ! empty( $context['user_message'] ) ? wp_strip_all_tags( (string) $context['user_message'] ) : '',
				'action'        => ! empty( $context['action'] ) ? sanitize_key( $context['action'] ) : '',
				'updated_at_gmt' => ! empty( $context['updated_at_gmt'] ) ? sanitize_text_field( $context['updated_at_gmt'] ) : '',
			);
		}

		/**
		 * Resolve the order referenced by a WooCommerce result URL.
		 *
		 * @param string $current_url Optional URL.
		 * @return WC_Order|false
		 */
		private static function get_order_from_result_request( $current_url = '' ) {
			$order_id  = 0;
			$order_key = '';

			if ( $current_url ) {
				$parts = wp_parse_url( $current_url );

				if ( ! empty( $parts['query'] ) ) {
					parse_str( $parts['query'], $query_args );
					$order_key = ! empty( $query_args['key'] ) ? wc_clean( wp_unslash( $query_args['key'] ) ) : '';
				}

				if ( ! empty( $parts['path'] ) && preg_match( '#/(?:order-received|checkout/order-received)/([0-9]+)/?#', $parts['path'], $matches ) ) {
					$order_id = absint( $matches[1] );
				}
			}

			if ( ! $order_id && function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
				$order_id = absint( get_query_var( 'order-received' ) );
			}

			if ( ! $order_key && isset( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$order_key = wc_clean( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			if ( $order_key && ! $order_id ) {
				$order_id = wc_get_order_id_by_order_key( $order_key );
			}

			$order = $order_id ? wc_get_order( $order_id ) : false;

			if ( ! $order ) {
				return false;
			}

			if ( $order_key && ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
				return false;
			}

			return $order;
		}

		/**
		 * Get Coin Booking Bridge checkout context when available.
		 *
		 * @return array
		 */
		private static function get_checkout_context() {
			if ( function_exists( 'cbb_get_checkout_context' ) ) {
				$context = cbb_get_checkout_context( get_current_user_id() );

				return is_array( $context ) ? $context : array();
			}

			return array(
				'mode'                  => 'money_purchase',
				'has_booking_items'     => false,
				'has_credit_products'   => false,
				'has_recovery_products' => false,
				'required_zencoins'     => 0,
				'available_zencoins'    => 0,
				'recovery_zencoins'     => 0,
				'projected_available_zencoins' => 0,
				'missing_zencoins'      => 0,
				'projected_missing_zencoins' => 0,
				'wallet_is_frozen'      => false,
				'blocking_reason'       => '',
			);
		}

		/**
		 * Render temporary checkout context debug block.
		 *
		 * This is intentionally simple and temporary so mode detection can be
		 * verified before UI branching is implemented.
		 *
		 * @return string
		 */
		private static function render_checkout_context_debug() {
			$context      = self::get_checkout_context();
			$gateway_rows = self::get_available_gateway_strategy_rows();
			$runtime      = self::get_gateway_runtime_context();
			$bootstrap    = self::get_native_card_runtime_bootstrap_summary();

			ob_start();
			?>
			<div class="zcf-context-debug" data-zcf-context-debug>
				<div class="zcf-context-debug__title"><?php esc_html_e( 'Checkout Mode Debug', 'zen-checkout-flow' ); ?></div>
				<ul class="zcf-context-debug__list">
					<li><strong><?php esc_html_e( 'Mode:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( isset( $context['mode'] ) ? $context['mode'] : 'money_purchase' ); ?></li>
					<li><strong><?php esc_html_e( 'Has booking items:', 'zen-checkout-flow' ); ?></strong> <?php echo ! empty( $context['has_booking_items'] ) ? esc_html__( 'Yes', 'zen-checkout-flow' ) : esc_html__( 'No', 'zen-checkout-flow' ); ?></li>
					<li><strong><?php esc_html_e( 'Has credit products:', 'zen-checkout-flow' ); ?></strong> <?php echo ! empty( $context['has_credit_products'] ) ? esc_html__( 'Yes', 'zen-checkout-flow' ) : esc_html__( 'No', 'zen-checkout-flow' ); ?></li>
					<li><strong><?php esc_html_e( 'Has recovery products:', 'zen-checkout-flow' ); ?></strong> <?php echo ! empty( $context['has_recovery_products'] ) ? esc_html__( 'Yes', 'zen-checkout-flow' ) : esc_html__( 'No', 'zen-checkout-flow' ); ?></li>
					<li><strong><?php esc_html_e( 'Required ZC:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( wc_format_decimal( isset( $context['required_zencoins'] ) ? $context['required_zencoins'] : 0, 2 ) ); ?></li>
					<li><strong><?php esc_html_e( 'Available ZC:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( wc_format_decimal( isset( $context['available_zencoins'] ) ? $context['available_zencoins'] : 0, 2 ) ); ?></li>
					<li><strong><?php esc_html_e( 'Recovery ZC:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( wc_format_decimal( isset( $context['recovery_zencoins'] ) ? $context['recovery_zencoins'] : 0, 2 ) ); ?></li>
					<li><strong><?php esc_html_e( 'Projected ZC:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( wc_format_decimal( isset( $context['projected_available_zencoins'] ) ? $context['projected_available_zencoins'] : 0, 2 ) ); ?></li>
					<li><strong><?php esc_html_e( 'Missing ZC:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( wc_format_decimal( isset( $context['missing_zencoins'] ) ? $context['missing_zencoins'] : 0, 2 ) ); ?></li>
					<li><strong><?php esc_html_e( 'Projected Missing ZC:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( wc_format_decimal( isset( $context['projected_missing_zencoins'] ) ? $context['projected_missing_zencoins'] : 0, 2 ) ); ?></li>
					<li><strong><?php esc_html_e( 'Wallet frozen:', 'zen-checkout-flow' ); ?></strong> <?php echo ! empty( $context['wallet_is_frozen'] ) ? esc_html__( 'Yes', 'zen-checkout-flow' ) : esc_html__( 'No', 'zen-checkout-flow' ); ?></li>
					<?php if ( ! empty( $context['blocking_reason'] ) ) : ?>
						<li><strong><?php esc_html_e( 'Blocking reason:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( $context['blocking_reason'] ); ?></li>
					<?php endif; ?>
					<?php if ( ! empty( $gateway_rows ) ) : ?>
						<li><strong><?php esc_html_e( 'Gateway strategies:', 'zen-checkout-flow' ); ?></strong></li>
						<?php foreach ( $gateway_rows as $gateway_row ) : ?>
							<li>
								<?php
								printf(
									'%1$s (%2$s): %3$s',
									esc_html( $gateway_row['id'] ),
									esc_html( $gateway_row['title'] ? $gateway_row['title'] : __( 'Untitled', 'zen-checkout-flow' ) ),
									esc_html( $gateway_row['strategy'] )
								);
								?>
							</li>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( ! empty( $runtime['wcpay_card']['available'] ) ) : ?>
						<li><strong><?php esc_html_e( 'Native card runtime:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( $runtime['wcpay_card']['runtime'] ); ?></li>
						<li><strong><?php esc_html_e( 'Card UI target:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( $runtime['wcpay_card']['ui'] ); ?></li>
						<li><strong><?php esc_html_e( 'Card submission target:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( $runtime['wcpay_card']['submission'] ); ?></li>
						<li><strong><?php esc_html_e( 'Card assets enqueued:', 'zen-checkout-flow' ); ?></strong> <?php echo ! empty( $bootstrap['assets_enqueued'] ) ? esc_html__( 'Yes', 'zen-checkout-flow' ) : esc_html__( 'No', 'zen-checkout-flow' ); ?></li>
						<?php if ( ! empty( $bootstrap['script_handles'] ) ) : ?>
							<li><strong><?php esc_html_e( 'Card script handles:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( 'sanitize_text_field', $bootstrap['script_handles'] ) ) ); ?></li>
						<?php endif; ?>
						<?php if ( ! empty( $bootstrap['data_keys'] ) ) : ?>
							<li><strong><?php esc_html_e( 'Card data keys:', 'zen-checkout-flow' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( 'sanitize_text_field', $bootstrap['data_keys'] ) ) ); ?></li>
						<?php endif; ?>
					<?php endif; ?>
				</ul>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Prepare the native WooPayments Card runtime assets for popup pages.
		 *
		 * This is a careful bootstrap step only. We load the real block runtime
		 * handle and the real data keys onto the page so the popup can truthfully
		 * probe readiness before we attempt the live Payment Element mount.
		 *
		 * @return array
		 */
		private static function prepare_native_card_runtime_assets() {
			$summary = self::get_native_card_runtime_bootstrap_summary();

			if ( empty( $summary['available'] ) || empty( $summary['script_handles'] ) ) {
				return $summary;
			}

			wp_enqueue_script( 'wc-settings' );

			foreach ( $summary['script_handles'] as $handle ) {
				if ( wp_script_is( $handle, 'registered' ) || wp_script_is( $handle, 'enqueued' ) ) {
					wp_enqueue_script( $handle );
				}
			}

			// WooPayments only enqueues its checkout block stylesheet on native
			// Woo pages. Popup pages such as custom landing/pricing pages need the
			// same stylesheet explicitly so the payment methods render correctly.
			if ( class_exists( 'WC_Payments_Utils' ) && class_exists( 'WC_Payments' ) && defined( 'WCPAY_PLUGIN_FILE' ) ) {
				WC_Payments_Utils::enqueue_style(
					'wc-blocks-checkout-style',
					plugins_url( 'dist/blocks-checkout.css', WCPAY_PLUGIN_FILE ),
					array(),
					WC_Payments::get_file_version( 'dist/checkout.css' ),
					'all'
				);
			}

			$summary['assets_enqueued'] = true;
			self::$native_card_bootstrap_summary = $summary;

			return $summary;
		}

		/**
		 * Discover the WooPayments Card block runtime scripts and data keys.
		 *
		 * @return array
		 */
		private static function get_native_card_runtime_bootstrap_summary() {
			if ( is_array( self::$native_card_bootstrap_summary ) ) {
				return self::$native_card_bootstrap_summary;
			}

			$summary = array(
				'available'       => false,
				'assets_enqueued' => false,
				'script_handles'  => array(),
				'data_keys'       => array(),
			);

			if ( ! class_exists( 'WC_Payments_Blocks_Payment_Method' ) ) {
				return $summary;
			}

			$payment_method = new WC_Payments_Blocks_Payment_Method();
			$payment_method->initialize();

			if ( ! $payment_method->is_active() ) {
				return $summary;
			}

			$summary['available']      = true;
			$summary['script_handles'] = array_values( array_filter( (array) $payment_method->get_payment_method_script_handles() ) );

			if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) && class_exists( '\Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry' ) ) {
				$container = \Automattic\WooCommerce\Blocks\Package::container();
				$registry  = $container->get( \Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry::class );
				$data      = $payment_method->get_payment_method_data();

				if ( $registry && is_array( $data ) ) {
					if ( ! $registry->exists( 'woocommerce_payments_data' ) ) {
						$registry->add( 'woocommerce_payments_data', $data );
					}

					if ( ! $registry->exists( 'paymentMethodData' ) ) {
						$registry->add(
							'paymentMethodData',
							array(
								'woocommerce_payments' => $data,
							)
						);
					}

					$summary['data_keys'] = array( 'woocommerce_payments_data', 'paymentMethodData' );
				}
			}

			self::$native_card_bootstrap_summary = $summary;

			return $summary;
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
		 * Remove a cart item.
		 */
		public static function ajax_remove_cart_item() {
			self::verify_ajax();

			if ( ! WC()->cart ) {
				wp_send_json_error( array( 'message' => __( 'Your cart is unavailable.', 'zen-checkout-flow' ) ) );
			}

			$cart_item_key = isset( $_POST['cart_item_key'] ) ? wc_clean( wp_unslash( $_POST['cart_item_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( '' === $cart_item_key || ! isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
				wp_send_json_error( array( 'message' => __( 'This cart item could not be found.', 'zen-checkout-flow' ) ) );
			}

			WC()->cart->remove_cart_item( $cart_item_key );
			WC()->cart->calculate_totals();

			if ( WC()->cart->is_empty() ) {
				wp_send_json_success(
					array(
						'html' => self::render_shell( '', 'auto' ),
						'step' => 'auto',
					)
				);
			}

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
		 * Complete an enough-Zencoin booking without a money gateway.
		 */
		public static function ajax_book_with_zencoins() {
			self::verify_ajax();

			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				wp_send_json_error( array( 'message' => __( 'Your cart is empty.', 'zen-checkout-flow' ) ) );
			}

			$context = self::get_checkout_context();
			$mode    = isset( $context['mode'] ) ? $context['mode'] : 'money_purchase';

			if ( 'zencoin_booking' !== $mode ) {
				wp_send_json_error( array( 'message' => __( 'This booking is not currently covered by your Zencoin wallet.', 'zen-checkout-flow' ) ) );
			}

			self::hydrate_checkout_customer_profile();
			WC()->cart->calculate_totals();

			if ( WC()->cart->needs_payment() ) {
				wp_send_json_error( array( 'message' => __( 'This cart still requires a payment method.', 'zen-checkout-flow' ) ) );
			}

			$order_result = self::create_zencoin_booking_order();

			if ( is_wp_error( $order_result ) ) {
				wp_send_json_error( array( 'message' => $order_result->get_error_message() ) );
			}

			$order_id = isset( $order_result['order_id'] ) ? absint( $order_result['order_id'] ) : 0;
			$data     = isset( $order_result['data'] ) && is_array( $order_result['data'] ) ? $order_result['data'] : array();
			$order    = wc_get_order( $order_id );

			if ( ! $order ) {
				wp_send_json_error( array( 'message' => __( 'Unable to create booking order.', 'zen-checkout-flow' ) ) );
			}

			try {
				do_action( 'woocommerce_checkout_order_processed', $order_id, $data, $order );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ) );
			}

			if ( $order->get_meta( '_cbb_coins_debited_transaction_id', true ) ) {
				$order->payment_complete();
				WC()->cart->empty_cart();

				wp_send_json_success(
					array(
						'redirect' => $order->get_checkout_order_received_url(),
					)
				);
			}

			wp_send_json_error( array( 'message' => __( 'Booking could not be completed with Zencoins. Please try again.', 'zen-checkout-flow' ) ) );
		}

		/**
		 * Add a selected recovery product to the cart and refresh checkout fragments.
		 */
		public static function ajax_add_recovery_product() {
			self::verify_ajax();

			if ( ! WC()->cart ) {
				wp_send_json_error( array( 'message' => __( 'Your cart is unavailable.', 'zen-checkout-flow' ) ) );
			}

			$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product      = wc_get_product( $variation_id ? $variation_id : $product_id );

			if ( ! $product || ! $product->is_purchasable() ) {
				wp_send_json_error( array( 'message' => __( 'This Zencoin plan is not available.', 'zen-checkout-flow' ) ) );
			}

			$offer = self::build_recovery_product_offer( $product );

			if ( empty( $offer ) ) {
				wp_send_json_error( array( 'message' => __( 'This product cannot be used for Zencoin recovery.', 'zen-checkout-flow' ) ) );
			}

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! empty( $cart_item['zcf_recovery_product'] ) ) {
					WC()->cart->remove_cart_item( $cart_item_key );
				}
			}

			$variation = array();

			if ( $variation_id && is_callable( array( $product, 'get_variation_attributes' ) ) ) {
				$variation = $product->get_variation_attributes();
			}

			$cart_item_key = WC()->cart->add_to_cart(
				$product_id,
				1,
				$variation_id,
				$variation,
				array(
					'zcf_recovery_product' => true,
				)
			);

			if ( ! $cart_item_key ) {
				wp_send_json_error( array( 'message' => __( 'Unable to add this Zencoin plan. Please try again.', 'zen-checkout-flow' ) ) );
			}

			WC()->cart->calculate_totals();

			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}

			wp_send_json_success(
				array(
					'cartItems'     => self::render_cart_items(),
					'paymentPanel'  => self::render_payment_panel( 'payment' ),
					'payButtonHtml' => self::render_primary_action(),
					'step'          => 'payment',
				)
			);
		}

		/**
		 * Remove recovery products added by the popup step flow.
		 */
		public static function ajax_remove_recovery_products() {
			self::verify_ajax();

			if ( ! WC()->cart ) {
				wp_send_json_error( array( 'message' => __( 'Your cart is unavailable.', 'zen-checkout-flow' ) ) );
			}

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! empty( $cart_item['zcf_recovery_product'] ) ) {
					WC()->cart->remove_cart_item( $cart_item_key );
				}
			}

			WC()->cart->calculate_totals();

			wp_send_json_success(
				array(
					'cartItems'     => self::render_cart_items(),
					'paymentPanel'  => self::render_payment_panel( self::get_ajax_step() ),
					'payButtonHtml' => self::render_primary_action(),
					'step'          => self::normalize_step( self::get_ajax_step() ),
				)
			);
		}

		/**
		 * Create a Woo order for wallet-only Zencoin booking.
		 *
		 * @return array|WP_Error
		 */
		private static function create_zencoin_booking_order() {
			add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'relax_zencoin_booking_checkout_fields' ), 20 );

			try {
				$checkout = WC()->checkout();
				$data     = self::get_zencoin_checkout_data();
				$order_id = $checkout->create_order( $data );

				if ( is_wp_error( $order_id ) ) {
					return $order_id;
				}

				$order = wc_get_order( $order_id );

				if ( ! $order ) {
					return new WP_Error( 'zcf_order_missing', __( 'Unable to create booking order.', 'zen-checkout-flow' ) );
				}

				return array(
					'order_id' => $order_id,
					'data'     => $data,
				);
			} catch ( Throwable $e ) {
				return new WP_Error( 'zcf_order_error', $e->getMessage() );
			} finally {
				remove_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'relax_zencoin_booking_checkout_fields' ), 20 );
			}
		}

		/**
		 * Relax billing/shipping required fields for wallet-only booking checkout.
		 *
		 * @param array $fields Checkout fields.
		 * @return array
		 */
		public static function relax_zencoin_booking_checkout_fields( $fields ) {
			foreach ( array( 'billing', 'shipping' ) as $fieldset_key ) {
				if ( empty( $fields[ $fieldset_key ] ) || ! is_array( $fields[ $fieldset_key ] ) ) {
					continue;
				}

				foreach ( $fields[ $fieldset_key ] as $key => $field ) {
					if ( 'billing_email' === $key ) {
						continue;
					}

					$fields[ $fieldset_key ][ $key ]['required'] = false;
				}
			}

			return $fields;
		}

		/**
		 * Populate the minimal Woo checkout POST shape for no-payment booking.
		 *
		 * @return array
		 */
		private static function get_zencoin_checkout_data() {
			$customer_data = self::get_checkout_customer_data();
			$checkout      = WC()->checkout();
			$data          = array(
				'payment_method'            => '',
				'terms'                     => 1,
				'terms-field'               => 1,
				'ship_to_different_address' => false,
				'createaccount'             => 0,
				'order_comments'            => '',
			);

			self::set_synthetic_checkout_post_value( 'woocommerce-process-checkout-nonce', wp_create_nonce( 'woocommerce-process_checkout' ) );
			self::set_synthetic_checkout_post_value( '_wpnonce', $_POST['woocommerce-process-checkout-nonce'] );
			self::set_synthetic_checkout_post_value( 'payment_method', '' );
			self::set_synthetic_checkout_post_value( 'terms', '1' );
			self::set_synthetic_checkout_post_value( 'terms-field', '1' );

			foreach ( $checkout->get_checkout_fields() as $fieldset ) {
				foreach ( $fieldset as $key => $field ) {
					if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
						continue;
					}

					if ( isset( $customer_data[ $key ] ) ) {
						self::set_synthetic_checkout_post_value( $key, $customer_data[ $key ] );
						$data[ $key ] = $customer_data[ $key ];
					} else {
						$data[ $key ] = '';
					}
				}
			}

			return $data;
		}

		/**
		 * Set checkout payload values in both POST and REQUEST for Woo internals.
		 *
		 * @param string $key   Field key.
		 * @param mixed  $value Field value.
		 * @return void
		 */
		private static function set_synthetic_checkout_post_value( $key, $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
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
				wp_send_json_error(
					array(
						'message'   => __( 'Please log in to continue.', 'zen-checkout-flow' ),
						'loggedOut' => true,
					)
				);
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
					'payButtonHtml' => self::render_primary_action(),
				)
			);
		}
	}

	ZCF_Zen_Checkout_Flow::init();
}
