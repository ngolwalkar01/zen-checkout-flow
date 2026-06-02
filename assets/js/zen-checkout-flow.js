(function ($) {
	'use strict';

	var currentStep = 'auto';
	var stepHistory = [];
	var popupHistoryArmed = false;

	function setLoading($shell, isLoading) {
		$shell.toggleClass('is-loading', !!isLoading);
	}

	function updateFragments($shell, data) {
		parkPersistentCheckoutHost();

		if (Object.prototype.hasOwnProperty.call(data, 'html')) {
			$shell.replaceWith(data.html);
			currentStep = data.step || getShellStep(getPopupStage()) || currentStep;
			attachPersistentCheckoutHost(getPopupStage());
			clearStaleCoinBalanceNotices(getPopupStage());
			return;
		}

		if (Object.prototype.hasOwnProperty.call(data, 'cartItems')) {
			$shell.find('[data-zcf-cart-items]').html(data.cartItems);
		}

		if (Object.prototype.hasOwnProperty.call(data, 'paymentPanel')) {
			$shell.find('[data-zcf-payment-panel]').html(data.paymentPanel);
		}

		if (Object.prototype.hasOwnProperty.call(data, 'payButtonHtml')) {
			$shell.find('[data-zcf-primary-action]').html(data.payButtonHtml);
		}

		if (data.step) {
			currentStep = data.step;
			$shell.find('.zcf-modal').attr('data-zcf-step', data.step);
		}

		attachPersistentCheckoutHost($shell);
		clearStaleCoinBalanceNotices($shell);
	}

	function getPersistentCheckoutHost() {
		return $('[data-zcf-persistent-checkout-host]').first();
	}

	function getPersistentCheckoutStash() {
		return $('[data-zcf-native-host-stash]').first();
	}

	function parkPersistentCheckoutHost() {
		var $host = getPersistentCheckoutHost();
		var $stash = getPersistentCheckoutStash();

		if (!$host.length || !$stash.length) {
			return;
		}

		$stash.append($host);
	}

	function attachPersistentCheckoutHost($shell) {
		var $host = getPersistentCheckoutHost();
		var $slot = $shell.find('[data-zcf-block-checkout-slot]').first();

		if (!$host.length || !$slot.length) {
			return null;
		}

		$slot.empty().append($host);
		clearStaleCoinBalanceNotices($shell);
	}

	function clearStaleCoinBalanceNotices($scope) {
		var patterns = [
			/current balance is/i,
			/you need \d+(?:[.,]\d+)? coins for these bookings/i
		];

		$scope.find('.wc-block-components-notice-banner, .woocommerce-error, [role="alert"]').each(function () {
			var $notice = $(this);
			var text = $notice.text() || '';
			var isCoinBalanceNotice = patterns.some(function (pattern) {
				return pattern.test(text);
			});

			if (isCoinBalanceNotice) {
				$notice.remove();
			}
		});
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

	function bookWithZencoins($shell) {
		setLoading($shell, true);

		return $.ajax({
			type: 'POST',
			url: zcfCheckout.ajaxUrl,
			data: {
				action: 'zcf_book_with_zencoins',
				nonce: zcfCheckout.nonce
			}
		})
			.done(function (response) {
				if (response && response.result === 'success' && response.redirect) {
					window.location.href = response.redirect;
					return;
				}

				if (response && response.success && response.data && response.data.redirect) {
					window.location.href = response.data.redirect;
					return;
				}

				showMessage(
					$shell,
					response && response.messages ? response.messages : (response && response.data ? response.data.message : zcfCheckout.i18n.error),
					'error'
				);
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

	function renderPopupShell($stage, skipResult, step) {
		return $.ajax({
			type: 'POST',
			url: zcfCheckout.ajaxUrl,
			data: {
				action: 'zcf_render_checkout',
				nonce: zcfCheckout.nonce,
				current_url: skipResult ? '' : window.location.href,
				zcf_step: step || 'auto'
			}
		}).done(function (response) {
			if (response && response.success && response.data && response.data.html) {
				$stage.html(response.data.html);
				currentStep = getShellStep($stage) || response.data.step || step || 'auto';
				attachPersistentCheckoutHost($stage);
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
		armPopupHistory();
		hasShell = $stage.find('[data-zcf-checkout-flow]').length > 0;

		if (!hasShell) {
			$stage.html('<div class="zcf-popup-loading" data-zcf-popup-loading>' + zcfCheckout.i18n.loading + '</div>');
			stepHistory = [];
			renderPopupShell($stage);
			return;
		}

		currentStep = getShellStep($stage) || currentStep;
		attachPersistentCheckoutHost($stage);

	}

	function reloadPopupToPayment(previousStep) {
		var url;

		try {
			window.sessionStorage.setItem('zcf_step_history', JSON.stringify([previousStep || 'auto']));
			window.sessionStorage.setItem('zcf_current_step', 'payment');
		} catch (error) {
			// Storage is only used to keep the popup back stack across reloads.
		}

		url = new URL(window.location.href);
		url.searchParams.set('zcf_open_checkout', '1');
		window.location.replace(url.toString());
	}

	function restoreStepState() {
		var storedHistory;
		var storedStep;

		try {
			storedHistory = window.sessionStorage.getItem('zcf_step_history');
			storedStep = window.sessionStorage.getItem('zcf_current_step');
			window.sessionStorage.removeItem('zcf_step_history');
			window.sessionStorage.removeItem('zcf_current_step');
		} catch (error) {
			storedHistory = '';
			storedStep = '';
		}

		if (storedHistory) {
			try {
				stepHistory = JSON.parse(storedHistory);
			} catch (error) {
				stepHistory = [];
			}
		}

		if (storedStep) {
			currentStep = storedStep;
		}
	}

	function shouldRedirectHomeOnClose() {
		return !!zcfCheckout.popupOwnsRoute && !!zcfCheckout.homeUrl;
	}

	function armPopupHistory() {
		if (!window.history || typeof window.history.pushState !== 'function') {
			return;
		}

		try {
			window.history.pushState({ zcfPopup: true, zcfStep: currentStep || 'auto' }, document.title, window.location.href);
			popupHistoryArmed = true;
		} catch (error) {
			popupHistoryArmed = false;
		}
	}

	function closePopup(options) {
		options = options || {};

		parkPersistentCheckoutHost();
		getPopup().removeClass('is-active').attr('aria-hidden', 'true');
		$('body').removeClass('zcf-popup-open');

		if (!options.suppressRedirect && shouldRedirectHomeOnClose()) {
			window.location.href = zcfCheckout.homeUrl;
		}
	}

	function getShellStep($scope) {
		var $modal = $scope.find('.zcf-modal[data-zcf-step]').first();

		return $modal.length ? String($modal.attr('data-zcf-step') || 'auto') : 'auto';
	}

	function addRecoveryProduct($button) {
		var $shell = $button.closest('[data-zcf-checkout-flow]');
		var $stage = getPopupStage();
		var previousStep = currentStep || getShellStep($shell) || 'auto';

		setLoading($shell, true);

		return $.ajax({
			type: 'POST',
			url: zcfCheckout.ajaxUrl,
			data: {
				action: 'zcf_add_recovery_product',
				nonce: zcfCheckout.nonce,
				product_id: $button.data('product-id') || 0,
				variation_id: $button.data('variation-id') || 0
			}
		})
			.done(function (response) {
				if (response && response.success) {
					reloadPopupToPayment(previousStep);
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

	function removeCartItem($button) {
		var $shell = $button.closest('[data-zcf-checkout-flow]');

		return request($shell, 'zcf_remove_cart_item', {
			cart_item_key: $button.data('zcf-remove-cart-item') || ''
		});
	}

	function goBackStep() {
		var previousStep = stepHistory.pop();
		var $stage = getPopupStage();
		var $shell = $stage.find('[data-zcf-checkout-flow]').first();

		if (!previousStep) {
			if (currentStep === 'payment' || getShellStep($stage) === 'payment') {
				previousStep = 'choose_plan';
			} else {
				closePopup();
				return;
			}
		}

		if (currentStep === 'payment') {
			setLoading($shell, true);

			$.ajax({
				type: 'POST',
				url: zcfCheckout.ajaxUrl,
				data: {
					action: 'zcf_remove_recovery_products',
					nonce: zcfCheckout.nonce,
					zcf_step: previousStep
				}
			}).always(function () {
				currentStep = previousStep;
				$stage.html('<div class="zcf-popup-loading" data-zcf-popup-loading>' + zcfCheckout.i18n.loading + '</div>');
				renderPopupShell($stage, true, previousStep);
			});
			return;
		}

		$stage.html('<div class="zcf-popup-loading" data-zcf-popup-loading>' + zcfCheckout.i18n.loading + '</div>');
		renderPopupShell($stage, true, previousStep);
	}

	function filterPlanCards($button) {
		var type = String($button.data('zcf-plan-tab') || 'all');
		var $chooser = $button.closest('.zcf-plan-chooser');

		$button
			.addClass('is-active')
			.siblings('[data-zcf-plan-tab]')
			.removeClass('is-active');

		$chooser.find('[data-zcf-plan-type]').each(function () {
			var $card = $(this);
			var cardType = String($card.data('zcf-plan-type') || '');

			$card.toggle(type === 'all' || cardType === type);
		});
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

	$(document).on('click', '[data-zcf-book-zencoins]', function () {
		bookWithZencoins($(this).closest('[data-zcf-checkout-flow]'));
	});

	$(document).on('click', '[data-zcf-add-recovery-product]', function () {
		addRecoveryProduct($(this));
	});

	$(document).on('click', '[data-zcf-remove-cart-item]', function () {
		removeCartItem($(this));
	});

	$(document).on('click', '[data-zcf-back]', function () {
		goBackStep();
	});

	$(document).on('click', '[data-zcf-plan-tab]', function () {
		filterPlanCards($(this));
	});

	$(document).on('click', '[data-zcf-login]', function () {
		if (openThemeLoginPopup()) {
			closePopup({ suppressRedirect: true });
		}
	});

	$(document).on('click', '[data-zcf-result-action]', function () {
		var action = $(this).data('zcf-result-action');
		var $stage = getPopupStage();

		if (action === 'retry') {
			$stage.html('<div class="zcf-popup-loading" data-zcf-popup-loading>' + zcfCheckout.i18n.loading + '</div>');
			renderPopupShell($stage, true, 'payment');
			return;
		}

		if (action === 'schedule') {
			$(document).trigger('zenCheckoutFlow:schedule');
			closePopup({ suppressRedirect: true });
			return;
		}

		closePopup({ suppressRedirect: true });
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
		restoreStepState();

		if (zcfCheckout.autoOpen) {
			openPopup();
		}
	});

	$(document).on('keydown', function (event) {
		if (event.key === 'Escape' && getPopup().hasClass('is-active')) {
			closePopup();
		}
	});

	$(window).on('popstate', function () {
		if (!popupHistoryArmed || !getPopup().hasClass('is-active')) {
			return;
		}

		goBackStep();

		if (getPopup().hasClass('is-active')) {
			armPopupHistory();
		}
	});
})(jQuery);
