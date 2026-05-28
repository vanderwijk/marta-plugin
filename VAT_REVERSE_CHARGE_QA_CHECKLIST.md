# VAT Reverse Charge Manual QA Checklist

Use this checklist on `https://martaonline.local` before disabling the old VAT plugin in production.

## Preconditions

- `marta-plugin` is active.
- Any previous VAT compliance plugin is disabled in Local.
- WooCommerce taxes are enabled and Dutch VAT rates are configured.
- Checkout page uses classic shortcode checkout (`[woocommerce_checkout]`).
- At least one taxable product is in cart.

## Quick setup

1. Add a taxable product to cart.
2. Go to checkout.
3. Ensure `VAT number (EU business)` field is visible under billing details.
4. Open DevTools Network tab (optional, useful while testing).

## Scenario 1: NL + empty VAT

1. Billing country: `Netherlands (NL)`.
2. Leave VAT field empty.
3. Place order.

Expected:
- Checkout succeeds.
- Dutch VAT is charged (normal B2C).
- Order has no reverse-charge behavior.

## Scenario 2: NL + valid NL VAT (domestic B2B)

1. Billing country: `Netherlands (NL)`.
2. VAT number: `NL123456789B01`.
3. Place order.

Expected:
- Checkout succeeds.
- Dutch VAT still charged (domestic => no reverse charge).
- Order meta includes `_marta_vies_status = valid`.

## Scenario 3: DE + empty VAT

1. Billing country: `Germany (DE)`.
2. Leave VAT field empty.
3. Place order.

Expected:
- Checkout succeeds.
- Full VAT charged (treated as B2C).

## Scenario 4: DE + valid DE VAT (reverse charge)

1. Billing country: `Germany (DE)`.
2. VAT number: `DE129273398`.
3. Place order.

Expected:
- Checkout succeeds.
- VAT becomes `0` (reverse charge applied).
- Order meta:
  - `_marta_vat_country = DE`
  - `_marta_vat_number = 129273398`
  - `_marta_vies_status = valid`
  - `_marta_vies_checked_at` is set
- Admin order metabox shows green `Valid` badge.
- Billing address includes `VAT: DE129273398`.
- Email/customer details includes reverse-charge legal text.

## Scenario 5: DE + VIES invalid VAT

1. Billing country: `Germany (DE)`.
2. VAT number: `DE000000000`.
3. Try placing order.

Expected:
- Checkout blocked.
- Error: VAT not valid in VIES.
- No order created.

## Scenario 6: DE + format-invalid VAT

1. Billing country: `Germany (DE)`.
2. VAT number: `XYZ`.
3. Try placing order.

Expected:
- Checkout blocked.
- Error: invalid VAT format for selected country.
- No order created.

## Scenario 7: US + VAT provided

1. Billing country: `United States (US)`.
2. Enter any VAT-like value.
3. Place order.

Expected:
- Checkout succeeds.
- Reverse-charge logic is not applied.
- Taxes follow existing US tax behavior in your shop config.

## Scenario 8: Simulate VIES unreachable

Recommended temporary snippet (mu-plugin or code snippet):

```php
add_filter('pre_http_request', function($pre, $args, $url) {
	if (false !== strpos($url, 'ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number')) {
		return new WP_Error('marta_vies_down', 'Simulated VIES outage');
	}
	return $pre;
}, 10, 3);
```

Test steps:
1. Billing country `DE`.
2. VAT number `DE129273398`.
3. Place order.

Expected:
- Checkout succeeds (not blocked).
- Full VAT charged (no reverse charge when VIES unreachable).
- Order meta `_marta_vies_unreachable = 1`.
- Admin warning notice appears on order screen.

Remove the snippet after testing.

## Scenario 9: Block checkout smoke test (optional)

Only if you migrate to WooCommerce block checkout later.

Expected:
- Same outcome as Scenario 4 for valid DE VAT.

## Scenario 10: HPOS smoke

If HPOS is enabled:
1. Re-run Scenario 4.
2. Confirm order meta is readable via WooCommerce APIs/admin.

Expected:
- `_marta_vies_status` remains available via `$order->get_meta('_marta_vies_status')`.

## Useful commands

Run automated smoke test:

```bash
wp --path="/Users/johanvanderwijk/Local Sites/martaonline/app/public" marta vat-self-test
```

## Sign-off checklist

- [ ] Scenarios 1-8 validated.
- [ ] Scenario 9 validated or marked not applicable.
- [ ] Scenario 10 validated if HPOS enabled.
- [ ] Old VAT plugin remains disabled on Local after successful validation.
- [ ] Ready to deploy via existing GitHub Action workflow.
