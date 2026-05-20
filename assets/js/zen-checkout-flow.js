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

		bindPaymentEmbedFrame($shell);
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

	function getPaymentEmbedFrame($scope) {
		var $shell = $scope && $scope.is('[data-zcf-checkout-flow]') ? $scope : $scope.find('[data-zcf-checkout-flow]').first();
		return $shell.find('[data-zcf-embed-payment-frame]').first();
	}

	function syncPaymentEmbedFrameHeight(frame, forcedHeight) {
		var $frame = $(frame);
		var nextHeight = forcedHeight || 0;

		if (!nextHeight) {
			try {
				if (frame.contentWindow && frame.contentWindow.location.origin === window.location.origin) {
					var doc = frame.contentWindow.document;
					nextHeight = Math.max(
						doc.body ? doc.body.scrollHeight : 0,
						doc.documentElement ? doc.documentElement.scrollHeight : 0,
						doc.documentElement ? doc.documentElement.offsetHeight : 0
					);
				}
			} catch (error) {
				nextHeight = 0;
			}
		}

		if (nextHeight) {
			$frame.height(Math.max(nextHeight + 24, 680));
		}
	}

	function maybeFollowPaymentUrl(url) {
		if (!url) {
			return;
		}

		try {
			var parsed = new URL(url, window.location.origin);

			if (parsed.origin === window.location.origin && parsed.pathname.indexOf('/order-received/') !== -1) {
				window.location.href = parsed.toString();
			}
		} catch (error) {
			// Ignore malformed URLs coming from child frames.
		}
	}

	function bindPaymentEmbedFrame($scope) {
		var $frame = getPaymentEmbedFrame($scope);

		if (!$frame.length) {
			return;
		}

		$frame.off('.zcfPayment');
		$frame.on('load.zcfPayment', function () {
			syncPaymentEmbedFrameHeight(this);

			try {
				if (this.contentWindow) {
					maybeFollowPaymentUrl(this.contentWindow.location.href);
				}
			} catch (error) {
				// Payment providers may navigate internally; the parent just ignores inaccessible states.
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
				bindPaymentEmbedFrame($stage);
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

		bindPaymentEmbedFrame($stage);
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

	$(document).on('click', '[data-zcf-to-payment]', function () {
		var $shell = $(this).closest('[data-zcf-checkout-flow]');
		var $right = $shell.find('.zcf-right');

		if ($right.length) {
			$right.get(0).scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
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

	$(window).on('message', function (event) {
		var originalEvent = event.originalEvent;
		var data = originalEvent ? originalEvent.data : null;

		if (!originalEvent || originalEvent.origin !== window.location.origin || !data || data.type !== 'zcfPaymentFrameState') {
			return;
		}

		var $frame = $('[data-zcf-embed-payment-frame]').filter(function () {
			return this.contentWindow === originalEvent.source;
		}).first();

		if (!$frame.length) {
			return;
		}

		if (data.height) {
			syncPaymentEmbedFrameHeight($frame.get(0), parseInt(data.height, 10));
		}

		maybeFollowPaymentUrl(data.url);
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
