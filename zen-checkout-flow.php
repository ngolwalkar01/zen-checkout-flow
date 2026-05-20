<?php
/**
 * Plugin Name: Zen Checkout Flow
 * Description: Popup-based WooCommerce checkout/cart flow for logged-in customers.
 * Version: 0.1.10
 * Author: Custom
 * Text Domain: zen-checkout-flow
 *
 * @package ZenCheckoutFlow
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZCF_Zen_Checkout_Flow' ) ) {
	final class ZCF_Zen_Checkout_Flow {

		const VERSION = '0.1.10';
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
			add_action( 'template_redirect', array( __CLASS__, 'maybe_render_embedded_payment_page' ), 1 );
			add_action( 'wp_footer', array( __CLASS__, 'render_popup_root' ) );
			add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_add_to_cart_to_popup' ), 20, 2 );
			add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_popup_payment_gateways' ), 20 );
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
					'embeddedPaymentUrl' => self::dependencies_loaded() ? self::get_embedded_payment_url() : '',
					'cartUrl'     => self::dependencies_loaded() ? wc_get_cart_url() : '',
					'autoOpen'    => self::should_auto_open_popup(),
					'myAccountUrl' => self::dependencies_loaded() ? wc_get_page_permalink( 'myaccount' ) : '',
					'isLoggedIn'   => is_user_logged_in(),
					'customer'    => self::get_checkout_customer_data(),
					'gatewayRuntime' => self::get_gateway_runtime_context(),
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
					<div class="zcf-popup-loading" data-zcf-popup-loading><?php esc_html_e( 'Loading checkout...', 'zen-checkout-flow' ); ?></div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render a minimal payment-only checkout surface for iframe requests.
		 */
		public static function maybe_render_embedded_payment_page() {
			if ( is_admin() || ! self::dependencies_loaded() || ! self::is_embedded_payment_request() ) {
				return;
			}

			$markup = self::render_embedded_payment_markup();

			status_header( 200 );
			nocache_headers();
			?>
			<!doctype html>
			<html <?php language_attributes(); ?>>
			<head>
				<meta charset="<?php bloginfo( 'charset' ); ?>">
				<meta name="viewport" content="width=device-width, initial-scale=1">
				<?php wp_head(); ?>
				<style>
					html, body {
						margin: 0;
						padding: 0;
						background: #ffffff;
					}
					body.zcf-embedded-payment {
						color: #1f1f1f;
					}
					.zcf-embedded-payment__wrap {
						padding: 0;
					}
					.zcf-embedded-payment__fields,
					.zcf-embedded-payment__review h3,
					.zcf-embedded-payment__review .woocommerce-checkout-review-order-table,
					.zcf-embedded-payment__review .shop_table,
					.zcf-embedded-payment__review .cart-subtotal,
					.zcf-embedded-payment__review .shipping,
					.zcf-embedded-payment__review .fee,
					.zcf-embedded-payment__review .tax-rate,
					.zcf-embedded-payment__review .order-total,
					.zcf-embedded-payment__review .recurring-total,
					.zcf-embedded-payment__review tfoot {
						display: none !important;
					}
					.zcf-embedded-payment__review #payment {
						margin: 0;
						padding: 0;
						background: transparent;
					}
					.zcf-embedded-payment__review .wc_payment_methods {
						margin: 0;
						padding: 0;
						list-style: none;
					}
					.zcf-embedded-payment__review .wc_payment_method {
						margin: 0 0 12px;
						padding: 12px 14px;
						border: 1px solid rgba(0, 0, 0, 0.12);
						border-radius: 10px;
						background: #f7f4ef;
					}
					.zcf-embedded-payment__review .wc_payment_method > label {
						display: flex;
						align-items: center;
						justify-content: space-between;
						gap: 12px;
					}
					.zcf-embedded-payment__review .payment_box {
						margin-top: 12px;
					}
					.zcf-embedded-payment__review .form-row.place-order {
						margin: 16px 0 0;
					}
					.zcf-embedded-payment__review #place_order {
						width: 100%;
						min-height: 48px;
						border-radius: 999px;
					}
				</style>
			</head>
			<body <?php body_class( array( 'woocommerce-checkout', 'zcf-embedded-payment' ) ); ?>>
				<?php wp_body_open(); ?>
				<div class="zcf-embedded-payment__wrap">
					<?php echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<script>
					(function () {
						function postState() {
							var body = document.body;
							var doc = document.documentElement;
							var height = Math.max(
								body ? body.scrollHeight : 0,
								body ? body.offsetHeight : 0,
								doc ? doc.clientHeight : 0,
								doc ? doc.scrollHeight : 0,
								doc ? doc.offsetHeight : 0
							);

							window.parent.postMessage(
								{
									type: 'zcfPaymentFrameState',
									height: height,
									url: window.location.href
								},
								window.location.origin
							);
						}

						window.addEventListener('load', function () {
							postState();
							window.setTimeout(postState, 300);
							window.setTimeout(postState, 900);
						});

						window.addEventListener('resize', postState);
						document.body.addEventListener('click', function () {
							window.setTimeout(postState, 200);
							window.setTimeout(postState, 700);
						});
						document.body.addEventListener('change', function () {
							window.setTimeout(postState, 200);
							window.setTimeout(postState, 700);
						});
					})();
				</script>
				<?php wp_footer(); ?>
			</body>
			</html>
			<?php
			exit;
		}

		/**
		 * Filter out gateways that should never be shown in the popup money-payment flow.
		 *
		 * This does not change how the wallet plugin works for Zencoin accounting;
		 * it only prevents wallet-like gateways from appearing as money payment
		 * methods inside the embedded popup payment surface.
		 *
		 * @param array $gateways Available gateways.
		 * @return array
		 */
		public static function filter_popup_payment_gateways( $gateways ) {
			if ( ! is_array( $gateways ) || ! self::is_embedded_payment_request() ) {
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
		 * Whether the current request is for the embedded payment surface.
		 *
		 * @return bool
		 */
		private static function is_embedded_payment_request() {
			return isset( $_GET['zcf_embed_payment'] ) && '1' === wc_clean( wp_unslash( $_GET['zcf_embed_payment'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Get the embedded payment surface URL.
		 *
		 * @return string
		 */
		private static function get_embedded_payment_url() {
			return add_query_arg(
				array(
					'zcf_embed_payment' => '1',
				),
				wc_get_checkout_url()
			);
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

			$context             = self::get_checkout_context();
			$uses_payment_embed  = self::uses_embedded_payment_surface( $context );

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

						<?php if ( ! $uses_payment_embed ) : ?>
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
			$context            = self::get_checkout_context();
			$mode               = isset( $context['mode'] ) ? $context['mode'] : 'money_purchase';

			ob_start();
			?>
			<?php echo self::render_checkout_context_debug(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
				<div class="zcf-embed-payment" data-zcf-embed-payment>
					<iframe
						class="zcf-embed-payment__frame"
						data-zcf-embed-payment-frame
						src="<?php echo esc_url( self::get_embedded_payment_url() ); ?>"
						title="<?php echo esc_attr__( 'Checkout payment', 'zen-checkout-flow' ); ?>"
						loading="eager"
					></iframe>
				</div>
			<?php elseif ( 'zencoin_booking' === $mode ) : ?>
				<?php echo self::render_mode_notice( sprintf( __( 'This booking is covered by your wallet. Required: %1$s ZC. Available: %2$s ZC.', 'zen-checkout-flow' ), wc_format_decimal( isset( $context['required_zencoins'] ) ? $context['required_zencoins'] : 0, 2 ), wc_format_decimal( isset( $context['available_zencoins'] ) ? $context['available_zencoins'] : 0, 2 ) ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="zcf-payment-label"><?php esc_html_e( 'Payment method:', 'zen-checkout-flow' ); ?></div>
				<div class="zcf-no-gateways"><?php esc_html_e( 'No payment gateway is needed when your booking is fully covered by Zencoins.', 'zen-checkout-flow' ); ?></div>
			<?php else : ?>
				<?php echo self::render_mode_notice( sprintf( __( 'You need %1$s more ZC to complete this booking. Add a membership, package, or drop-in to continue.', 'zen-checkout-flow' ), wc_format_decimal( isset( $context['missing_zencoins'] ) ? $context['missing_zencoins'] : 0, 2 ) ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="zcf-payment-label"><?php esc_html_e( 'Payment method:', 'zen-checkout-flow' ); ?></div>
				<div class="zcf-no-gateways"><?php esc_html_e( 'Payment methods stay hidden until a recovery credit product is added to the cart.', 'zen-checkout-flow' ); ?></div>
			<?php endif; ?>
			<?php
			return ob_get_clean();
		}

		/**
		 * Render a native WooCommerce checkout form with hidden customer fields.
		 *
		 * We keep billing inputs out of sight because the Zen flow is intended to
		 * reuse customer data captured during signup/account creation.
		 *
		 * @return string
		 */
		private static function render_native_checkout_form() {
			if ( ! self::dependencies_loaded() || ! WC()->cart || ! WC()->checkout() ) {
				return '<div class="zcf-no-gateways">' . esc_html__( 'Checkout is unavailable right now.', 'zen-checkout-flow' ) . '</div>';
			}

			$checkout = WC()->checkout();

			ob_start();
			?>
			<form name="checkout" method="post" class="checkout woocommerce-checkout zcf-native-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">
				<div class="zcf-native-checkout__hidden-fields" aria-hidden="true">
					<?php echo self::render_hidden_customer_fields( $checkout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="ship_to_different_address" value="0" />
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); ?>" />
				</div>

				<div class="zcf-native-checkout__payment">
					<?php woocommerce_checkout_payment(); ?>
				</div>
			</form>
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
		 * Render the minimal embedded payment markup with the native checkout runtime.
		 *
		 * This keeps WooCommerce's expected checkout structure (`form.checkout`
		 * and `#order_review`) intact while visually exposing only the payment UI.
		 *
		 * @return string
		 */
		private static function render_embedded_payment_markup() {
			$checkout = WC()->checkout();

			ob_start();

			do_action( 'woocommerce_before_checkout_form', $checkout );

			if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
				echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );

				return ob_get_clean();
			}
			?>
			<form name="checkout" method="post" class="checkout woocommerce-checkout zcf-embedded-payment__form" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">
				<div class="zcf-embedded-payment__fields" aria-hidden="true">
					<?php echo self::render_hidden_customer_fields( $checkout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="ship_to_different_address" value="0" />
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( wc_get_checkout_url() ); ?>" />
				</div>

				<div id="order_review" class="woocommerce-checkout-review-order zcf-embedded-payment__review">
					<?php do_action( 'woocommerce_checkout_order_review' ); ?>
				</div>
			</form>
			<?php

			do_action( 'woocommerce_after_checkout_form', $checkout );

			return ob_get_clean();
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
				<button type="button" class="zcf-pay-button is-disabled" disabled>
					<?php esc_html_e( 'Book with Zencoins', 'zen-checkout-flow' ); ?>
				</button>
				<?php
			else :
				?>
				<button type="button" class="zcf-pay-button is-disabled" disabled>
					<?php esc_html_e( 'Add credits to continue', 'zen-checkout-flow' ); ?>
				</button>
				<?php
			endif;

			return ob_get_clean();
		}

		/**
		 * Whether the current checkout mode uses the embedded payment surface.
		 *
		 * @param array $context Checkout context.
		 * @return bool
		 */
		private static function uses_embedded_payment_surface( $context ) {
			$mode = isset( $context['mode'] ) ? $context['mode'] : 'money_purchase';

			return in_array( $mode, array( 'money_purchase', 'mixed_recovery' ), true );
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
			$wcpay_card      = array(
				'available'   => false,
				'gateway_id'  => 'woocommerce_payments',
				'strategy'    => 'inline_sdk',
				'runtime'     => 'wcpay_blocks_checkout_provider',
				'ui'          => 'stripe_payment_element',
				'submission'  => 'store_api_checkout',
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
				'embedded_surface'         => true,
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
			$context = self::get_checkout_context();
			$gateway_rows = self::get_available_gateway_strategy_rows();
			$runtime      = self::get_gateway_runtime_context();

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
					<?php endif; ?>
				</ul>
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
