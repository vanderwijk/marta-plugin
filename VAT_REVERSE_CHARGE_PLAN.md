# Implementation Plan — EU VAT Field + VIES Reverse Charge for `marta-plugin`

**Audience:** An AI coding agent executing this plan end-to-end.
**Goal:** Add a VAT number field to the WooCommerce checkout on martaonline.eu, validate it against the EU VIES database, and apply the EU intra-community reverse charge (0% VAT) when the number is valid and the customer is in another EU country than the shop. The code lives inside the existing `marta-plugin` plugin folder. Replaces the third-party "European VAT Compliance Assistant for WooCommerce" plugin.

---

## 0. Decisions already made (do not re-litigate)

- **Reverse-charge rule:** Apply 0% VAT only when (a) VIES returns *valid*, AND (b) billing country is in the EU, AND (c) billing country ≠ shop base country. Domestic B2B keeps full VAT.
- **VIES failure handling:**
  - Format-invalid number → block checkout with inline error.
  - VIES returns *invalid* → block checkout with inline error.
  - VIES times out / SOAP fault / network error → allow the order, charge full VAT, store a flag on the order (`_marta_vies_unreachable = 1`), and surface an admin notice on the order edit screen so the shop owner can manually approve a credit note if needed.
- **Field is optional.** Empty = treat as B2C, charge full VAT, no validation performed.
- **Scaffold exists.** `marta-plugin` already has a main bootstrap file and (presumably) an autoloader; this plan only adds new files.

---

## 1. Pre-flight (the agent must do this before writing code)

1. **Checkout type is confirmed: CLASSIC (shortcode `[woocommerce_checkout]`).** The user has verified the Checkout page uses the shortcode, not the block. Therefore:
   - `Marta_VAT_Checkout_Classic` (section 3.4) is the **primary** code path — implement and test this first.
   - `Marta_VAT_Checkout_Block` (section 3.3) is a **forward-compatibility shim** — still implement it (so a future migration to the block checkout doesn't silently break VAT collection) but the test plan in section 4 only requires the classic scenarios. Mark the block scenario as "smoke test only".
   - Server-side reverse-charge logic in `Marta_VAT_Tax` (section 3.5) runs for both paths and is mandatory.
2. **Inspect `marta-plugin/` and report:**
   - The main plugin file (header `Plugin Name: …`).
   - The PHP namespace / class-loading convention (PSR-4 autoloader? `require_once` includes? plain functions in a `inc/` directory?).
   - The minimum PHP version declared.
   - WooCommerce version (must be ≥ 8.6 for the block `register_additional_checkout_field` API; if lower, agent must bump compatibility or fall back to a Slots/Fills React component — note in handback).
3. **Do not edit `wp-config.php`, theme files, or any other plugin.** All changes must be inside `marta-plugin/`.
4. **Disable the third-party "European VAT Compliance Assistant for WooCommerce" plugin** only after the new code is verified working in a staging environment (the user runs LocalWP locally; suggest testing there first, then deploy via the existing GitHub Action to the DigitalOcean droplet).

---

## 2. File structure to add inside `marta-plugin/`

```
marta-plugin/
├── includes/
│   └── vat-reverse-charge/
│       ├── class-marta-vat-bootstrap.php       # Wires everything; included from main plugin file
│       ├── class-marta-vies-client.php         # SOAP/REST call to VIES + caching
│       ├── class-marta-vat-validator.php       # Format prefilter + orchestration
│       ├── class-marta-vat-checkout-block.php  # Block-checkout field registration
│       ├── class-marta-vat-checkout-classic.php# Classic-checkout field + validation
│       ├── class-marta-vat-tax.php             # Reverse-charge logic (sets VAT exempt)
│       ├── class-marta-vat-order.php           # Persist VAT data on order, admin display, emails
│       └── data/
│           └── eu-vat-formats.php              # Per-country regex array (returns array)
└── marta-plugin.php                            # MODIFY: require the bootstrap file
```

Naming follows WordPress's `class-…-…` convention. Adjust to the existing plugin's conventions if `marta-plugin` uses PSR-4 namespaces — the agent should mirror what's already there.

---

## 3. Class-by-class spec

### 3.1 `Marta_VIES_Client`

**Responsibility:** Call the EU VIES service and return one of three states: `valid`, `invalid`, `unreachable`.

- **Endpoint:** Prefer the REST endpoint introduced by the European Commission:
  `GET https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number` (POST with JSON body `{"countryCode":"NL","vatNumber":"123456789B01"}`). Document the SOAP fallback as a comment: `https://ec.europa.eu/taxation_customs/vies/services/checkVatService`.
- Use `wp_remote_post()` with `timeout => 8` seconds. Do **not** use raw cURL or `file_get_contents`.
- **Caching:** Use transients.
  - On `valid` → cache for 30 days. Key: `marta_vies_v1_{country}_{number_hash}` where `number_hash = md5(strtoupper(preg_replace('/\s+/','', $vat)))`.
  - On `invalid` → cache for 24 hours (so a typo isn't re-hammered).
  - On `unreachable` → do not cache; let the next request retry.
- **Return shape:** an associative array
  ```php
  [
    'status'   => 'valid' | 'invalid' | 'unreachable',
    'name'     => string|null,   // Trader name from VIES if returned (some MS hide this)
    'address'  => string|null,
    'checked_at' => gmdate('c'),
    'raw'      => array|null,    // For debugging only — do NOT log to options
  ]
  ```
- **Method signature:** `public function check( string $country_code, string $vat_number ): array`.
- **Logging:** On `unreachable`, write a single line to `error_log()` with the country, the masked VAT (first 2 + last 2 chars, rest as `*`), and the HTTP status / error message. Never log the full VAT number.

### 3.2 `Marta_VAT_Validator`

**Responsibility:** Format pre-filter (cheap, no network) then delegate to VIES.

- Load `data/eu-vat-formats.php` (a `return [ 'NL' => '/^[0-9]{9}B[0-9]{2}$/i', 'DE' => '/^[0-9]{9}$/', … ]` array — agent must populate all 27 EU country codes; reference: https://ec.europa.eu/taxation_customs/vies/faq.html ).
- Public method: `public function validate( string $billing_country, string $raw_vat ): array` returning the same shape as `Marta_VIES_Client::check()` *plus* a `reason` key when status is `invalid` (`'format'`, `'country_mismatch'`, `'vies_invalid'`).
- Strip spaces, dots, dashes; upper-case. If the user typed the country prefix (e.g. `NL123456789B01`), strip it before VIES (VIES wants country code and number separately) but reject if the prefix conflicts with `$billing_country` (set `reason = 'country_mismatch'`).
- If `$billing_country` is not in the EU country list, return `['status' => 'invalid', 'reason' => 'not_eu']` (the field simply shouldn't have been submitted).

### 3.3 `Marta_VAT_Checkout_Block`

**Responsibility:** Register the VAT field on the block checkout using WooCommerce's first-party API.

- Hook on `woocommerce_init`:
  ```php
  woocommerce_register_additional_checkout_field([
      'id'            => 'marta/vat-number',
      'label'         => __( 'VAT number (EU business)', 'marta-plugin' ),
      'location'      => 'address',     // shows in billing address group
      'type'          => 'text',
      'required'      => false,
      'attributes'    => [ 'autocomplete' => 'off', 'pattern' => '[A-Za-z0-9 .-]{4,20}' ],
      'sanitize_callback' => fn( $v ) => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) ),
      'validate_callback' => [ $this, 'validate_field' ], // returns WP_Error on failure
  ]);
  ```
- `validate_field( $value, $group, $wc_object )`:
  - Empty value → return `true` (optional field).
  - Read billing country from `$wc_object` (a `WC_Customer` or `WC_Order` depending on context).
  - Call `Marta_VAT_Validator::validate()`.
  - On `valid` → store `$value` in transient `marta_vat_pending_{customer_session_id}` so the tax recalculation hook can read it without another network call.
  - On `invalid` → return `new WP_Error( 'marta_vat_invalid', __( 'This VAT number is not valid in VIES. Please check or leave the field blank.', 'marta-plugin' ) )`.
  - On `unreachable` → return `true` (don't block) but set a session flag `marta_vat_vies_unreachable = true`.

### 3.4 `Marta_VAT_Checkout_Classic`

**Responsibility:** Mirror the above for the shortcode checkout.

- `woocommerce_checkout_fields` filter → add `billing_vat_number` field in the `billing` group, `priority => 35`.
- `woocommerce_checkout_process` action → run the same validator; call `wc_add_notice()` on failure.
- `woocommerce_checkout_update_order_meta` → persist `_billing_vat_number` and `_marta_vies_status` on the order.
- `woocommerce_after_checkout_validation` is *not* enough on its own — the field must also be exposed via `woocommerce_checkout_get_value` so it survives reloads.

### 3.5 `Marta_VAT_Tax` (the actual reverse-charge mechanic)

**Responsibility:** Make the cart/order recompute with 0% VAT when conditions are met.

- The cleanest mechanism in WooCommerce is `WC()->customer->set_is_vat_exempt( true )`. This automatically zero-rates all line items for the rest of the request.
- Hook on `woocommerce_after_calculate_totals` (priority 1, before payment totals are read):
  ```php
  add_action( 'woocommerce_after_calculate_totals', [ $this, 'maybe_apply_reverse_charge' ], 1 );
  ```
- Logic inside `maybe_apply_reverse_charge( $cart )`:
  1. Get billing country from `WC()->customer->get_billing_country()`.
  2. Get shop base country from `wc_get_base_location()['country']`.
  3. Get stored VAT validation status from the session/transient written by the checkout field.
  4. Apply exemption only if **all** of:
     - billing country is in EU list,
     - billing country ≠ shop base country,
     - VAT status is `valid`.
  5. Otherwise call `WC()->customer->set_is_vat_exempt( false )` (defensive — in case it was set in a prior request).
- For the **block checkout** there is an extra wrinkle: the Store API calculates totals server-side via `woocommerce_store_api_checkout_update_order_from_request`. Hook here too to read the registered additional field value off the order, run validation, and set the customer exempt before totals are finalized.
- Hook `woocommerce_checkout_create_order` to copy the session VAT data onto the order as meta:
  - `_marta_vat_number`
  - `_marta_vat_country`
  - `_marta_vies_status` (`valid` | `unreachable`)
  - `_marta_vies_checked_at` (ISO 8601)
  - `_marta_vat_trader_name` if VIES returned one
- Hook `woocommerce_order_get_formatted_billing_address` to append the VAT number under the billing address on invoices/emails: `format: "VAT: NL123456789B01"`.

### 3.6 `Marta_VAT_Order`

**Responsibility:** Admin UX + email rendering + REST exposure.

- `add_meta_box` on the Shop Order screen ("VAT / Reverse charge") showing:
  - VAT number (read-only).
  - VIES status badge (green = valid, amber = unreachable, gray = not provided).
  - Checked-at timestamp.
  - A "Re-check now" button (AJAX → calls `Marta_VIES_Client::check()` bypassing cache, updates meta).
- Also support **HPOS** (High-Performance Order Storage). Use `OrderUtil::custom_orders_table_usage_is_enabled()` and read/write meta via `$order->get_meta()` / `$order->update_meta_data()` + `$order->save()`, never `get_post_meta()`.
- Add a column on `WC_Admin_List_Table_Orders` ("VAT") showing the VIES status badge for quick scanning.
- WC email hook `woocommerce_email_customer_details` → print the VAT number and "Reverse charge — VAT to be accounted for by the recipient under Article 196 of Council Directive 2006/112/EC" line when the order was zero-rated this way. (This wording is legally required on the invoice; the user should confirm with their accountant, but this is the standard EU phrasing.)

### 3.7 `Marta_VAT_Bootstrap`

Simple wire-up class:
```php
final class Marta_VAT_Bootstrap {
    public static function init(): void {
        $vies      = new Marta_VIES_Client();
        $validator = new Marta_VAT_Validator( $vies );
        ( new Marta_VAT_Checkout_Block( $validator ) )->register();
        ( new Marta_VAT_Checkout_Classic( $validator ) )->register();
        ( new Marta_VAT_Tax() )->register();
        ( new Marta_VAT_Order( $vies ) )->register();
    }
}
add_action( 'plugins_loaded', [ 'Marta_VAT_Bootstrap', 'init' ], 20 );
```
Required from the existing main `marta-plugin.php` file with a single `require_once __DIR__ . '/includes/vat-reverse-charge/class-marta-vat-bootstrap.php';` (plus the dependency classes — or wire them through the existing autoloader if there is one).

---

## 4. Test plan (the agent must run all of these on LocalWP before declaring done)

For each scenario, confirm: (a) the field renders, (b) validation behaves as specified, (c) the **cart total** changes to reflect 0% VAT where expected, (d) the order meta is written correctly, (e) the order edit screen shows the badge.

| # | Billing country | VAT number | Expected outcome |
|---|---|---|---|
| 1 | NL | (empty) | Full Dutch VAT, no field validation, no meta written. |
| 2 | NL | `NL123456789B01` (valid Dutch VAT) | Full Dutch VAT (domestic B2B — no reverse charge). `_marta_vies_status = valid` stored. |
| 3 | DE | (empty) | Full Dutch VAT (treated as B2C). |
| 4 | DE | valid German VAT (use a real one from a public source, e.g. `DE129273398` — SAP) | **0% VAT**, order meta written, "Reverse charge" line in confirmation email. |
| 5 | DE | `DE000000000` (format-valid, VIES-invalid) | Checkout blocked, inline error "not valid in VIES". |
| 6 | DE | `XYZ` (format-invalid) | Checkout blocked, inline error "format" |
| 7 | US | anything | Field accepts but reverse-charge logic ignores (not EU). Full VAT or zero depending on existing tax rules — verify behavior matches what shop already does for US. |
| 8 | DE | valid number, **VIES simulated down** (block outbound to `ec.europa.eu` in `/etc/hosts` or via a filter `pre_http_request`) | Order goes through, charged full VAT, `_marta_vies_unreachable = 1`, admin notice visible. |
| 9 | Same as #4 but on the **block** checkout (only if the user later migrates) | Smoke test only — same outcome as #4. Skip if block checkout is not enabled. |
| 10 | HPOS enabled site | Re-run #4 and confirm meta is queryable via `$order->get_meta('_marta_vies_status')`. |

Also: run `phpcs --standard=WordPress` over the new files; fix any errors.

---

## 5. Edge cases / things to watch

- **Greece** uses `EL` as its VIES prefix, not `GR`. Map this in the validator.
- **Northern Ireland** VAT numbers use the `XI` prefix and are still valid for VIES (post-Brexit). Include `XI` even though it isn't an EU member state.
- **Cyprus** numbers contain a letter at the end (`CY12345678X`).
- **Caching invalidation:** If the shop owner manually marks a VAT as invalid via "Re-check now" and gets a fresh result, the transient must be overwritten, not added.
- **GDPR:** The VAT number on the order is personal data of a sole trader. Do not write it to logs. The `Marta_VIES_Client` already masks the number in logs — keep that discipline everywhere.
- **Coupons that already give 100% discount** — `set_is_vat_exempt(true)` is fine because tax is computed on line subtotal regardless. No special case needed.
- **Shipping tax:** `set_is_vat_exempt(true)` also zero-rates shipping tax, which is correct for intra-community supply.
- **Block checkout JS contract:** `woocommerce_register_additional_checkout_field` automatically renders a React input — no custom JS bundle needed. Do not try to enqueue a competing JS file.
- **Translations:** Wrap all user-facing strings in `__( …, 'marta-plugin' )` and ship a `.pot` update.

---

## 6. Deliverables checklist for the executing agent

- [ ] All files in section 2 created.
- [ ] `marta-plugin.php` updated with one `require_once` (or autoloader entry).
- [ ] `eu-vat-formats.php` populated for all 27 EU states plus `EL` and `XI`.
- [ ] All 10 test scenarios pass on LocalWP.
- [ ] PHPCS WordPress standard passes.
- [ ] A short `CHANGELOG.md` entry in the plugin describing what was added.
- [ ] A handback note to the user listing: which checkout type was detected, the WooCommerce version found, any deviation from this plan and why.

---

## 7. Out of scope (do **not** build)

- A settings page. All behavior is hard-coded per section 0; if Marta later wants toggles, add them in a follow-up.
- MOSS / OSS reporting, quarterly VAT summary exports, ICP (Intracommunautaire prestaties) reporting. The old plugin did this; this rewrite only handles the checkout-time field + reverse charge.
- Subscriptions / recurring order handling. This shop has no subscriptions and none are planned, so no renewal-side VAT logic is needed and `WC_Subscriptions` integration must not be added.
- B2C distance-selling thresholds — assume the shop charges Dutch VAT to all EU consumers (the current default since the OSS scheme came in). If Marta is OSS-registered and wants per-country consumer VAT, that's a separate project.
- Front-end "valid ✓" indicator as the user types. Validation happens on submit only — keeps VIES traffic down and avoids leaking typing patterns.

---

## 8. Known bug from the first implementation (must fix)

During post-implementation smoke testing on 2026-05-28, the field rendered correctly and was included in the `update_order_review` AJAX payload, but the tax line did not change when a valid German VAT was entered with a German billing country, and no inline error appeared when an obviously invalid value was entered. Root cause: the validator was only wired into `woocommerce_checkout_process` (the form-submit gate), not into the AJAX recalculation that fires whenever a checkout field changes. As a result `WC()->session->get('marta_vat_status')` was never populated during recalcs, so `Marta_VAT_Tax::maybe_apply_reverse_charge()` always saw "no valid VAT" and never called `set_is_vat_exempt(true)`.

**Required fix in `Marta_VAT_Checkout_Classic`** — add a second hook so validation runs both at AJAX-recalc time and at submit time:

```php
add_action( 'woocommerce_checkout_update_order_review', [ $this, 'on_ajax_recalc' ], 10, 1 );

public function on_ajax_recalc( string $post_data ): void {
    parse_str( $post_data, $parsed );
    $country = $parsed['billing_country']    ?? '';
    $vat     = $parsed['billing_vat_number'] ?? '';

    if ( '' === trim( $vat ) ) {
        WC()->session->set( 'marta_vat_status', null );
        WC()->session->set( 'marta_vat_number', '' );
        return;
    }

    $result = $this->validator->validate( $country, $vat );
    WC()->session->set( 'marta_vat_status',      $result['status'] );   // valid | invalid | unreachable
    WC()->session->set( 'marta_vat_number',      $vat );
    WC()->session->set( 'marta_vat_country',     $country );
    WC()->session->set( 'marta_vies_checked_at', $result['checked_at'] ?? '' );

    if ( 'invalid' === $result['status'] ) {
        wc_add_notice(
            __( 'This VAT number is not valid in VIES. Please check or leave the field blank.', 'marta-plugin' ),
            'error'
        );
    }
}
```

Notes:
- **Do not remove** the existing `woocommerce_checkout_process` hook. Keep it as the final submit-time gate; the AJAX hook is additive.
- After the fix, the customer should see the tax line drop to €0 in real time as they fill the VAT field (no submit required), and see an inline error immediately if the number is malformed or VIES-rejected.
- Re-run test scenarios 4 and 5 from section 4. Both must now pass *before submit*, not only at order creation.
- Detected by Claude during browser-based smoke testing: country DE, VAT `DE129273398` (SAP), expected €0 tax, observed €73. Same field with `XX-NOT-A-VAT` produced no notice and no tax change — confirming the validator wasn't running during AJAX at all.

---

## 9. Second bug from the first implementation (must fix): VIES REST endpoint is unreachable from the server

After the section-8 fix shipped, the AJAX hook was confirmed live (invalid VATs produce the inline error correctly). But valid VATs still don't trigger reverse charge. The WordPress debug log shows:

```
Marta VIES unreachable [DE12*****98]: wp_error: cURL error 56:
OpenSSL SSL_read: error:0A000126:SSL routines::unexpected eof while reading, errno 0
```

cURL error 56 with "unexpected eof while reading" is a TLS-transport disconnect *after* the handshake — the server closed the socket without sending an HTTP response. The validator therefore returns `unreachable`, which by spec is a silent allow (no notice, no zero-rating), which is what we observe in the browser.

This is not a bug in our code path; it's the EC REST endpoint behaving badly. **Fix: stop using REST and use the SOAP endpoint instead.** The SOAP service at `…/services/checkVatService` is the canonical, decades-old, well-behaved endpoint and is what every other VIES integration uses.

### 9.1 Replace `Marta_VIES_Client::check()` with a SOAP implementation

```php
public function check( string $country_code, string $vat_number ): array {
    $country = strtoupper( $country_code );
    $number  = preg_replace( '/[^A-Za-z0-9]/', '', $vat_number );

    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:tns="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
        . '<soap:Body><tns:checkVat>'
        . '<tns:countryCode>' . esc_html( $country ) . '</tns:countryCode>'
        . '<tns:vatNumber>' . esc_html( $number ) . '</tns:vatNumber>'
        . '</tns:checkVat></soap:Body></soap:Envelope>';

    $response = wp_remote_post(
        'https://ec.europa.eu/taxation_customs/vies/services/checkVatService',
        [
            'timeout'     => 8,
            'httpversion' => '1.1', // important: avoid HTTP/2 quirks with EC front-end
            'headers'     => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction'   => '',
                'User-Agent'   => 'marta-plugin/1.0 (+https://martaonline.eu)', // empty UA is rejected
                'Accept'       => 'text/xml',
            ],
            'body'        => $envelope,
        ]
    );

    if ( is_wp_error( $response ) ) {
        $this->log_unreachable( $country, $number, 'wp_error: ' . $response->get_error_message() );
        return [ 'status' => 'unreachable', 'name' => null, 'address' => null, 'checked_at' => gmdate( 'c' ), 'raw' => null ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( 200 !== $code || ! $body ) {
        $this->log_unreachable( $country, $number, 'http_' . $code );
        return [ 'status' => 'unreachable', 'name' => null, 'address' => null, 'checked_at' => gmdate( 'c' ), 'raw' => null ];
    }

    // Parse SOAP response. Suppress libxml errors and recover from minor malformedness.
    $prev = libxml_use_internal_errors( true );
    $xml  = simplexml_load_string( $body );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    if ( ! $xml ) {
        $this->log_unreachable( $country, $number, 'xml_parse_failed' );
        return [ 'status' => 'unreachable', 'name' => null, 'address' => null, 'checked_at' => gmdate( 'c' ), 'raw' => null ];
    }

    $xml->registerXPathNamespace( 's', 'urn:ec.europa.eu:taxud:vies:services:checkVat:types' );
    $valid_nodes = $xml->xpath( '//s:valid' );
    $name_nodes  = $xml->xpath( '//s:name' );
    $addr_nodes  = $xml->xpath( '//s:address' );
    $fault       = $xml->xpath( '//*[local-name()="Fault"]' );

    if ( $fault ) {
        // SOAP Fault means MS_UNAVAILABLE, INVALID_INPUT (handled upstream), etc. Treat as unreachable.
        $this->log_unreachable( $country, $number, 'soap_fault' );
        return [ 'status' => 'unreachable', 'name' => null, 'address' => null, 'checked_at' => gmdate( 'c' ), 'raw' => null ];
    }

    $is_valid = $valid_nodes && (string) $valid_nodes[0] === 'true';
    $name     = $name_nodes ? trim( (string) $name_nodes[0] ) : null;
    $address  = $addr_nodes ? trim( (string) $addr_nodes[0] ) : null;

    return [
        'status'     => $is_valid ? 'valid' : 'invalid',
        'name'       => $name && $name !== '---' ? $name : null,
        'address'    => $address && $address !== '---' ? $address : null,
        'checked_at' => gmdate( 'c' ),
        'raw'        => null, // do not persist raw SOAP envelope
    ];
}
```

### 9.2 Why each non-obvious detail matters

- **`httpversion => '1.1'`**: the EC front-end sometimes EOFs HTTP/2 connections from cloud-IP ranges. Forcing 1.1 sidesteps this and is what produced the cURL 56 error.
- **Non-empty `User-Agent`**: WP's default UA is empty, which some EC front-ends reject silently.
- **`SOAPAction: ''` header**: SOAP 1.1 requires the header to exist even if empty; some intermediaries drop the request otherwise.
- **`simplexml` over `SoapClient`**: the PHP `SoapClient` extension isn't installed on all hosts, and pre-fetching the WSDL adds a slow extra round-trip. Hand-built envelope is faster and dependency-free.
- **Treat SOAP Faults as `unreachable`, not `invalid`**: faults mean "the member state's database is down" or similar transport problems, not "this VAT is wrong". Spec rule: don't block checkout on transport issues.

### 9.3 Optional REST fallback

If the EC SOAP endpoint also goes down (rare but it happens during maintenance windows), the agent *may* add a fallback to the REST endpoint *after* SOAP fails — but only after confirming SOAP works first. Do not implement REST as the primary path again.

### 9.4 Verification (must rerun after fix)

From the droplet:
```
curl -sS -m 8 -o /tmp/vies.xml -w "HTTP %{http_code} | %{time_total}s\n" \
  --http1.1 \
  -A 'marta-plugin/1.0' \
  -H 'Content-Type: text/xml; charset=utf-8' \
  -H 'SOAPAction: ' \
  --data '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><soap:Body><tns:checkVat><tns:countryCode>DE</tns:countryCode><tns:vatNumber>129273398</tns:vatNumber></tns:checkVat></soap:Body></soap:Envelope>' \
  'https://ec.europa.eu/taxation_customs/vies/services/checkVatService'
grep -E '(valid|name)' /tmp/vies.xml
```
Expected: `HTTP 200`, and `<valid>true</valid>` somewhere in the response.

Then in the browser: reload `/checkout/`, set country to Germany, enter `DE129273398`. Tax line must drop to €0 (after at most one AJAX recalc — see also section 8 ordering note: exemption must be applied early enough that the *current* recalc reflects the zero rate).
