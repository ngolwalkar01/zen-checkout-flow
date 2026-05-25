# Zen Checkout Flow

Popup-based WooCommerce cart and checkout shell for logged-in customers.

## Current MVP

- Shortcode: `[zen_checkout_flow]`
- Logged-in customers see:
  - profile avatar, full name, and email
  - custom cart item cards
  - coupon field
  - order summary
  - available WooCommerce payment gateways
  - pay button that redirects to the normal WooCommerce checkout for final payment processing
- Logged-out visitors see a login-required state.
- Empty carts show an empty-cart state.
- AJAX coupon apply/remove refreshes the custom cart and payment panel.
- Enough-Zencoin booking carts can be completed from the popup without showing payment gateways.
- Insufficient-Zencoin booking carts hide gateways and show the Figma-aligned recovery path:
  - 0 ZC customers see an in-popup plan chooser.
  - customers missing a smaller amount see a compact buy-missing-ZC prompt.
  - choosing a recovery product adds it to cart, reloads the page with the popup open, and lands on mixed-recovery payment so the Woo Blocks Store API runtime has fresh cart state.
  - recovery suggestions only include CBB recovery-eligible products: packages, paid drop-ins, and memberships.
  - the popup back arrow returns from payment to the previous recovery step and removes the selected recovery product.
- Temporary checkout-mode debug panel can surface Coin Booking Bridge cart classification when CBB is active.
- Mixed-recovery order result states from Coin Booking Bridge can auto-open on WooCommerce order-received URLs:
  - `completed` shows the purchase-and-booking success state.
  - `payment_failed` shows the payment retry state.
  - `booking_full` shows the class-full schedule state.
  - `booking_failed` shows the technical booking-failed state.
- Back and close buttons trigger JavaScript events so your existing popup can close/navigate:
  - `zenCheckoutFlow:back`
  - `zenCheckoutFlow:close`

## Usage

Place this shortcode inside the body of your existing login/account popup:

```text
[zen_checkout_flow]
```

Optional title override:

```text
[zen_checkout_flow title="Buy now:"]
```

## JavaScript Integration

Your existing popup can listen for these events:

```js
jQuery(document).on('zenCheckoutFlow:close', function (event, $shell) {
  // Close your custom popup here.
});

jQuery(document).on('zenCheckoutFlow:back', function (event, $shell) {
  // Navigate back to the previous popup step here.
});

jQuery(document).on('zenCheckoutFlow:schedule', function () {
  // Return the customer to the booking schedule here.
});
```

## Filters

Point the insufficient-Zencoin fallback link at a custom packages/memberships page:

```php
add_filter( 'zcf_recovery_products_url', function () {
  return home_url( '/zencoins/' );
} );
```

## Git Setup

From this folder:

```powershell
cd "D:\Backup\Personal\Experiment\zen-checkout-flow"
git init
git branch -M main
git add .
git commit -m "Initial Zen Checkout Flow MVP"
```

Then add your remote:

```powershell
git remote add origin <your-repo-url>
git push -u origin main
```

Because Git is initialized inside `zen-checkout-flow`, sibling plugin folders will not be tracked.

## Next Planned Steps

- Replace checkout redirect with true popup step-by-step checkout.
- Persist chosen gateway into WooCommerce checkout session.
- Add cart quantity/remove actions.
- Add booking/subscription-specific product detail layout.
- Add design variants for the next screenshots.
- Add mobile-specific interaction polish.
