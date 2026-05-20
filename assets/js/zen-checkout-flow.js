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

		probeNativeCardRuntime($shell);
	}

	function probeNativeCardRuntime($shell) {
		var $root = $shell.find('[data-zcf-native-card-root]');
		var state = getNativeCardRuntimeState($root);

		if (!state) {
			return;
		}

		state.$root
			.addClass('is-probed')
			.html('<div class="zcf-native-payment-card__probe">' + state.lines.join('') + '</div>');

		mountNativeCardRuntime(state);
	}

	function getNativeCardRuntimeState($root) {
		var runtime = zcfCheckout.gatewayRuntime && zcfCheckout.gatewayRuntime.wcpay_card ? zcfCheckout.gatewayRuntime.wcpay_card : null;
		var bootstrap = zcfCheckout.nativeCardBootstrap || {};
		var hasWcSettings = !!(window.wc && window.wc.wcSettings && typeof window.wc.wcSettings.getSetting === 'function');
		var wcpayData = hasWcSettings ? window.wc.wcSettings.getSetting('woocommerce_payments_data', null) : null;
		var hasPaymentMethodData = hasWcSettings ? window.wc.wcSettings.getSetting('paymentMethodData', null) : null;
		var hasBlocksRegistry = !!(window.wc && window.wc.wcBlocksRegistry && typeof window.wc.wcBlocksRegistry.getPaymentMethods === 'function');
		var paymentMethods = hasBlocksRegistry ? window.wc.wcBlocksRegistry.getPaymentMethods() : null;
		var cardPaymentMethod = paymentMethods && paymentMethods.woocommerce_payments ? paymentMethods.woocommerce_payments : null;
		var hasReactDom = !!(window.ReactDOM && typeof window.ReactDOM.createRoot === 'function');
		var lines = [];

		if (!$root.length || !runtime || !runtime.available) {
			return null;
		}

		lines.push('<div><strong>Runtime handle:</strong> ' + escapeHtml(runtime.runtime || 'n/a') + '</div>');
		lines.push('<div><strong>Assets enqueued:</strong> ' + (bootstrap.assets_enqueued ? 'Yes' : 'No') + '</div>');
		lines.push('<div><strong>wcSettings available:</strong> ' + (hasWcSettings ? 'Yes' : 'No') + '</div>');
		lines.push('<div><strong>wcBlocksRegistry available:</strong> ' + (hasBlocksRegistry ? 'Yes' : 'No') + '</div>');
		lines.push('<div><strong>woocommerce_payments_data:</strong> ' + (wcpayData ? 'Present' : 'Missing') + '</div>');
		lines.push('<div><strong>paymentMethodData.woocommerce_payments:</strong> ' + (hasPaymentMethodData && hasPaymentMethodData.woocommerce_payments ? 'Present' : 'Missing') + '</div>');
		lines.push('<div><strong>Registered card method:</strong> ' + (cardPaymentMethod ? 'Present' : 'Missing') + '</div>');
		lines.push('<div><strong>ReactDOM.createRoot:</strong> ' + (hasReactDom ? 'Available' : 'Missing') + '</div>');

		return {
			$root: $root,
			runtime: runtime,
			bootstrap: bootstrap,
			cardPaymentMethod: cardPaymentMethod,
			hasReactDom: hasReactDom,
			lines: lines
		};
	}

	function mountNativeCardRuntime(state) {
		var rootNode;
		var element;

		if (!state || !state.$root.length || !state.cardPaymentMethod || !state.hasReactDom) {
			return;
		}

		rootNode = state.$root.get(0);
		element = state.cardPaymentMethod.content || null;

		if (!element) {
			return;
		}

		try {
			if (window.wp && window.wp.element && typeof window.wp.element.isValidElement === 'function' && window.wp.element.isValidElement(element) && typeof window.wp.element.cloneElement === 'function') {
				element = window.wp.element.cloneElement(element);
			}

			if (!rootNode.__zcfCardReactRoot) {
				rootNode.innerHTML = '';
				rootNode.__zcfCardReactRoot = window.ReactDOM.createRoot(rootNode);
			}

			rootNode.__zcfCardReactRoot.render(element);
			state.$root.addClass('is-mounted').removeClass('is-probed');
		} catch (error) {
			console.error('ZCF native card mount failed.', error);
			state.$root
				.addClass('is-probed')
				.html(
					'<div class="zcf-native-payment-card__probe">' +
						state.lines.join('') +
						'<div><strong>Mount attempt:</strong> Failed</div>' +
						'<div><strong>Error:</strong> ' + escapeHtml(error && error.message ? error.message : 'Unknown error') + '</div>' +
					'</div>'
				);
		}
	}

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
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
				probeNativeCardRuntime($stage);
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

		probeNativeCardRuntime($stage);

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
