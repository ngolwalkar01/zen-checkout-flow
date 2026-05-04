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

				showMessage($shell, response && response.data ? response.data.message : zcfCheckout.i18n.error, 'error');
			})
			.fail(function () {
				showMessage($shell, zcfCheckout.i18n.error, 'error');
			})
			.always(function () {
				setLoading($shell, false);
			});
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
		var url = zcfCheckout.checkoutUrl;

		if (paymentMethod) {
			request($shell, 'zcf_choose_payment_method', {
				payment_method: paymentMethod
			}).always(function () {
				window.location.href = url;
			});
			return;
		}

		window.location.href = url;
	});

	$(document).on('click', '[data-zcf-back]', function () {
		$(document).trigger('zenCheckoutFlow:back', [$(this).closest('[data-zcf-checkout-flow]')]);
	});

	$(document).on('click', '[data-zcf-close]', function () {
		$(document).trigger('zenCheckoutFlow:close', [$(this).closest('[data-zcf-checkout-flow]')]);
	});
})(jQuery);
