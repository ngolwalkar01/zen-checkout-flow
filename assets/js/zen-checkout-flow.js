(function ($) {
	'use strict';

	function setLoading($shell, isLoading) {
		$shell.toggleClass('is-loading', !!isLoading);
	}

	function updateFragments($shell, data) {
		if (data.cartItems) {
			$shell.find('[data-zcf-cart-items]').html(data.cartItems);
		}

		if (data.paymentPanel) {
			$shell.find('[data-zcf-payment-panel]').html(data.paymentPanel);
		}

		if (data.payButtonHtml) {
			$shell.find('[data-zcf-primary-action]').html(data.payButtonHtml);
		}

		bootNativeCheckout($shell);
	}

	function showMessage($shell, message, type) {
		var $message = $shell.find('.zcf-message');

		if (!$message.length) {
			$message = $('<div class="zcf-message" aria-live="polite"></div>');
			$shell.find('.zcf-right').prepend($message);
		}

		$message
			.removeClass('is-error is-success')
			.addClass(type === 'success' ? 'is-success' : 'is-error')
			.html(message || zcfCheckout.i18n.error);
	}

	function showCheckoutResult($shell, message, type) {
		var $result = $shell.find('[data-zcf-checkout-result]');

		if (!$result.length) {
			$result = $('<div class="zcf-checkout-result" data-zcf-checkout-result aria-live="polite"></div>');
			$shell.find('.zcf-right').append($result);
		}

		$result
			.removeClass('is-error is-success')
			.addClass(type === 'success' ? 'is-success' : 'is-error')
			.html(message || '');
	}

	function bootNativeCheckout($scope) {
		var $shell = $scope && $scope.is('[data-zcf-checkout-flow]') ? $scope : $scope.find('[data-zcf-checkout-flow]').first();
		var $form = $shell.find('form.zcf-native-checkout');
		var $selectedMethod;

		if (!$form.length) {
			return;
		}

		$form.find('input[name="terms"]').prop('checked', true);

		if (!$form.find('input[name="payment_method"]:checked').length) {
			$form.find('input[name="payment_method"]').first().prop('checked', true).trigger('change');
		}

		if (window.wc_checkout_form) {
			try {
				window.wc_checkout_form.$checkout_form = $form;
				$form.attr('novalidate', 'novalidate');

				$form.off('.zcfNative');
				$form.on('submit.zcfNative', function (event) {
					return window.wc_checkout_form.submit.call(window.wc_checkout_form, event);
				});
				$form.on('click.zcfNative', 'input[name="payment_method"]', window.wc_checkout_form.payment_method_selected);
				$form.on('input.zcfNative validate.zcfNative change.zcfNative focusout.zcfNative', '.input-text, select, input:checkbox', window.wc_checkout_form.validate_field);
				$form.on('change.zcfNative', 'select.shipping_method, input[name^="shipping_method"], #ship-to-different-address input, .update_totals_on_change select, .update_totals_on_change input[type="radio"], .update_totals_on_change input[type="checkbox"]', window.wc_checkout_form.trigger_update_checkout);
				$form.on('change.zcfNative', '.address-field select', window.wc_checkout_form.input_changed);
				$form.on('change.zcfNative', '.address-field input.input-text, .update_totals_on_change input.input-text', window.wc_checkout_form.maybe_input_changed);
				$form.on('keydown.zcfNative', '.address-field input.input-text, .update_totals_on_change input.input-text', window.wc_checkout_form.queue_update_checkout);
				$form.on('blur.zcfNative', '#billing_address_1, #shipping_address_1', window.wc_checkout_form.address_field_blur);

				if (typeof window.wc_checkout_form.init_payment_methods === 'function') {
					window.wc_checkout_form.init_payment_methods();
				}

				$selectedMethod = $form.find('input[name="payment_method"]:checked').first();

				if ($selectedMethod.length && typeof window.wc_checkout_form.payment_method_selected === 'function') {
					window.wc_checkout_form.payment_method_selected.call($selectedMethod.get(0), $.Event('click'));
				}

				$(document.body).trigger('update_checkout');
			} catch (error) {
				// Fall back to basic form submission if native checkout init is unavailable.
			}
		}
	}

	function refreshActivePaymentMethod($scope) {
		var $shell = $scope && $scope.is('[data-zcf-checkout-flow]') ? $scope : $scope.find('[data-zcf-checkout-flow]').first();
		var $form = $shell.find('form.zcf-native-checkout');
		var $selectedMethod;

		if (!$form.length || !window.wc_checkout_form || typeof window.wc_checkout_form.payment_method_selected !== 'function') {
			return;
		}

		$selectedMethod = $form.find('input[name="payment_method"]:checked').first();

		if (!$selectedMethod.length) {
			return;
		}

		window.setTimeout(function () {
			try {
				window.wc_checkout_form.$checkout_form = $form;
				window.wc_checkout_form.payment_method_selected.call($selectedMethod.get(0), $.Event('click'));
				$(document.body).trigger('wc-credit-card-form-init');
			} catch (error) {
				// Keep the popup usable even if a gateway-specific re-init is unavailable.
			}
		}, 120);
	}

	function persistPaymentMethod(paymentMethod) {
		if (!paymentMethod) {
			return $.Deferred().resolve().promise();
		}

		return $.ajax({
			type: 'POST',
			url: zcfCheckout.ajaxUrl,
			data: {
				action: 'zcf_choose_payment_method',
				nonce: zcfCheckout.nonce,
				payment_method: paymentMethod
			}
		});
	}

	function openThemeLoginPopup() {
		var checkoutReturnUrl;

		try {
			checkoutReturnUrl = new URL(window.location.href);
			checkoutReturnUrl.searchParams.set('zcf_open_checkout', '1');
			window.sessionStorage.setItem('zenctuary_post_auth_redirect', checkoutReturnUrl.toString());
		} catch (error) {
			// Ignore URL/storage issues and continue with the popup fallback.
		}

		if (window.zenctuaryAuth && typeof window.zenctuaryAuth.openModal === 'function') {
			window.zenctuaryAuth.openModal('login');
			return true;
		}

		var trigger = document.querySelector('[data-auth="login"]');

		if (trigger) {
			trigger.click();
			return true;
		}

		return false;
	}

	function shouldUseThemeLogin() {
		return !zcfCheckout.isLoggedIn;
	}

	function openLoginFlowOrFallback() {
		if (shouldUseThemeLogin() && openThemeLoginPopup()) {
			return true;
		}

		return false;
	}

	function request($shell, action, payload) {
		setLoading($shell, true);

		return $.ajax({
			type: 'POST',
			url: zcfCheckout.ajaxUrl,
			data: $.extend(
				{
					action: action,
					nonce: zcfCheckout.nonce
				},
				payload || {}
			)
		})
			.done(function (response) {
				if (response && response.success) {
					updateFragments($shell, response.data || {});
					return;
				}

				if (response && response.data && response.data.loggedOut && openLoginFlowOrFallback()) {
					closePopup();
					return;
				}

				showMessage($shell, response && response.data ? response.data.message : zcfCheckout.i18n.error, 'error');
			})
			.fail(function () {
				showMessage($shell, zcfCheckout.i18n.error, 'error');
			})
			.always(function () {
				setLoading($shell, false);
			});
	}

	function getPopup() {
		return $('#zcf-popup');
	}

	function getPopupStage() {
		return getPopup().find('[data-zcf-popup-stage]');
	}

	function renderPopupShell($stage) {
		return $.ajax({
			type: 'POST',
			url: zcfCheckout.ajaxUrl,
			data: {
				action: 'zcf_render_checkout',
				nonce: zcfCheckout.nonce
			}
		}).done(function (response) {
			if (response && response.success && response.data && response.data.html) {
				$stage.html(response.data.html);
				bootNativeCheckout($stage);
				return;
			}

			$stage.html('<div class="zcf-popup-loading">' + zcfCheckout.i18n.error + '</div>');
		}).fail(function () {
			$stage.html('<div class="zcf-popup-loading">' + zcfCheckout.i18n.error + '</div>');
		});
	}

	function openPopup() {
		var $popup = getPopup();
		var $stage = getPopupStage();
		var hasShell;

		if (!$popup.length || !$stage.length) {
			return;
		}

		if (openLoginFlowOrFallback()) {
			return;
		}

		$popup.addClass('is-active').attr('aria-hidden', 'false');
		$('body').addClass('zcf-popup-open');
		hasShell = $stage.find('[data-zcf-checkout-flow]').length > 0;

		if (!hasShell) {
			$stage.html('<div class="zcf-popup-loading" data-zcf-popup-loading>' + zcfCheckout.i18n.loading + '</div>');
			renderPopupShell($stage);
			return;
		}

		bootNativeCheckout($stage);
	}

	function closePopup() {
		getPopup().removeClass('is-active').attr('aria-hidden', 'true');
		$('body').removeClass('zcf-popup-open');
	}

	function isCartOrCheckoutUrl(url) {
		var targets = [zcfCheckout.cartUrl, zcfCheckout.checkoutUrl].filter(Boolean);
		var normalizedUrl = parseComparableUrl(url);

		return targets.some(function (target) {
			var normalizedTarget = parseComparableUrl(target);

			return normalizedUrl.origin === normalizedTarget.origin && normalizedUrl.pathname === normalizedTarget.pathname;
		});
	}

	function parseComparableUrl(url) {
		var parsedUrl = new URL(String(url || ''), window.location.href);

		return {
			origin: parsedUrl.origin,
			pathname: parsedUrl.pathname.replace(/\/+$/, '')
		};
	}

	$(document).on('submit', '[data-zcf-coupon]', function (event) {
		event.preventDefault();

		var $form = $(this);
		var $shell = $form.closest('[data-zcf-checkout-flow]');
		var code = $.trim($form.find('[name="coupon_code"]').val());

		request($shell, 'zcf_apply_coupon', {
			coupon_code: code
		}).done(function (response) {
			if (response && response.success) {
				showMessage($shell, '', 'success');
			}
		});
	});

	$(document).on('click', '[data-zcf-remove-coupon]', function () {
		var $button = $(this);
		var $shell = $button.closest('[data-zcf-checkout-flow]');

		request($shell, 'zcf_remove_coupon', {
			coupon_code: $button.data('zcf-remove-coupon')
		});
	});

	$(document).on('change', '.zcf-native-checkout input[name="payment_method"]', function () {
		persistPaymentMethod($(this).val());
	});

	$(document).on('click', '[data-zcf-to-payment]', function () {
		var $shell = $(this).closest('[data-zcf-checkout-flow]');
		var $right = $shell.find('.zcf-right');

		if ($right.length) {
			$right.get(0).scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	});

	$(document).on('click', '[data-zcf-pay]', function () {
		var $shell = $(this).closest('[data-zcf-checkout-flow]');
		var $form = $shell.find('form.zcf-native-checkout');
		var paymentMethod = $form.find('input[name="payment_method"]:checked').val();
		var $button = $(this);
		var originalText = $button.text();

		if (!$form.length) {
			showCheckoutResult($shell, zcfCheckout.i18n.error, 'error');
			return;
		}

		if (!paymentMethod) {
			showCheckoutResult($shell, zcfCheckout.i18n.error, 'error');
			return;
		}

		$button.prop('disabled', true).addClass('is-loading').text(zcfCheckout.i18n.processing);
		setLoading($shell, true);
		showCheckoutResult($shell, '', 'success');
		$form.find('input[name="terms"]').prop('checked', true);

		persistPaymentMethod(paymentMethod).always(function () {
			$form.trigger('submit');

			window.setTimeout(function () {
				$button.prop('disabled', false).removeClass('is-loading').text(originalText);
				setLoading($shell, false);
			}, 1500);
		});
	});

	$(document.body).on('checkout_error', function (event, errorMessage) {
		var $shell = $('[data-zcf-checkout-flow]').first();

		if (!$shell.length) {
			return;
		}

		setLoading($shell, false);
		$shell.find('[data-zcf-pay]').prop('disabled', false).removeClass('is-loading');
		showCheckoutResult($shell, errorMessage || zcfCheckout.i18n.error, 'error');
	});

	$(document.body).on('updated_checkout', function () {
		var $shell = getPopupStage().find('[data-zcf-checkout-flow]').first();

		if (!$shell.length) {
			return;
		}

		refreshActivePaymentMethod($shell);
	});

	$(document).on('click', '[data-zcf-back]', function () {
		closePopup();
	});

	$(document).on('click', '[data-zcf-login]', function () {
		if (openThemeLoginPopup()) {
			closePopup();
		}
	});

	$(document).on('click', '[data-zcf-close]', function () {
		closePopup();
	});

	$(document).on('click', '[data-zcf-popup-close], [data-zcf-open-checkout]', function (event) {
		event.preventDefault();

		if ($(this).is('[data-zcf-popup-close]')) {
			closePopup();
			return;
		}

		openPopup();
	});

	$(document).on('click', 'a[href]', function (event) {
		if (!isCartOrCheckoutUrl(this.href)) {
			return;
		}

		event.preventDefault();
		openPopup();
	});

	$(document.body).on('added_to_cart', function () {
		var $stage = getPopupStage();
		var $shell;

		openPopup();
		$shell = $stage.find('[data-zcf-checkout-flow]').first();

		if ($shell.length) {
			request($shell, 'zcf_refresh_checkout');
		}
	});

	$(function () {
		if (zcfCheckout.autoOpen) {
			openPopup();
		}
	});

	$(document).on('keydown', function (event) {
		if (event.key === 'Escape' && getPopup().hasClass('is-active')) {
			closePopup();
		}
	});
})(jQuery);
