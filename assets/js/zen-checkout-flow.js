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

		if (data.payButtonText) {
			$shell.find('[data-zcf-pay]').text(data.payButtonText);
		}
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

	function openThemeLoginPopup() {
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

	function openPopup() {
		var $popup = getPopup();
		var $stage = getPopupStage();

		if (!$popup.length || !$stage.length) {
			return;
		}

		if (openLoginFlowOrFallback()) {
			return;
		}

		$popup.addClass('is-active').attr('aria-hidden', 'false');
		$('body').addClass('zcf-popup-open');
		$stage.html('<div class="zcf-popup-loading">' + zcfCheckout.i18n.loading + '</div>');

		$.ajax({
			type: 'POST',
			url: zcfCheckout.ajaxUrl,
			data: {
				action: 'zcf_render_checkout',
				nonce: zcfCheckout.nonce
			}
		})
			.done(function (response) {
				if (response && response.success && response.data && response.data.html) {
					$stage.html(response.data.html);
					return;
				}

				$stage.html('<div class="zcf-popup-loading">' + zcfCheckout.i18n.error + '</div>');
			})
			.fail(function () {
				$stage.html('<div class="zcf-popup-loading">' + zcfCheckout.i18n.error + '</div>');
			});
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

	$(document).on('change', '.zcf-gateway input[name="payment_method"]', function () {
		var $input = $(this);
		var $shell = $input.closest('[data-zcf-checkout-flow]');

		$shell.find('.zcf-gateway').removeClass('is-selected');
		$input.closest('.zcf-gateway').addClass('is-selected');

		request($shell, 'zcf_choose_payment_method', {
			payment_method: $input.val()
		});
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
		var paymentMethod = $shell.find('input[name="payment_method"]:checked').val();
		var $button = $(this);
		var originalText = $button.text();
		var checkoutUrl = zcfCheckout.checkoutAjaxUrl;
		var data = $.extend({}, zcfCheckout.customer || {}, {
			'payment_method': paymentMethod || '',
			'terms': '1',
			'woocommerce-process-checkout-nonce': zcfCheckout.checkoutNonce,
			'_wp_http_referer': window.location.pathname
		});

		if (!checkoutUrl) {
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

		request($shell, 'zcf_choose_payment_method', {
			payment_method: paymentMethod
		}).always(function () {
			$.ajax({
				type: 'POST',
				url: checkoutUrl,
				data: data
			})
				.done(function (response) {
					if (response && response.result === 'success') {
						if (response.redirect && response.redirect.indexOf('order-received') === -1) {
							window.location.href = response.redirect;
							return;
						}

						showCheckoutResult(
							$shell,
							'Payment complete. Your confirmation will be sent by email. <a href="' + zcfCheckout.myAccountUrl + '">View your account</a>.',
							'success'
						);
						request($shell, 'zcf_refresh_checkout');
						return;
					}

					showCheckoutResult($shell, response && response.messages ? response.messages : zcfCheckout.i18n.error, 'error');
				})
				.fail(function () {
					showCheckoutResult($shell, zcfCheckout.i18n.error, 'error');
				})
				.always(function () {
					$button.prop('disabled', false).removeClass('is-loading').text(originalText);
					setLoading($shell, false);
				});
		});
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
		openPopup();
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
