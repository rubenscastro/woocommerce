# Payment Method Deduplication — Spike Findings

**Status:** Spike / investigation
**Scope:** Detecting and resolving duplicated payment methods across providers on
Settings → Payments, and whether that flow is impactful in practice.
**Bottom line:** Detection works well and generically. Actual *resolution* is
bottlenecked by Stripe — for the majority of live Stripe stores, WooCommerce core
cannot disable a Stripe payment method, because Stripe keeps the enabled-method
state server-side. The flow is still fully useful wherever the method being
disabled is **not** a Stripe-under-PMC method.

---

## TL;DR

- A merchant can enable the same canonical method (Card, Klarna, iDEAL…) through
  more than one provider at once. The dedup flow detects those collisions and lets
  the merchant keep one provider and disable the rest.
- **Detection** is provider-agnostic, cheap, and reliable.
- **Resolution** depends on being able to *disable* the losing implementations.
  Core can do that for standalone gateways and for integrations that register a
  resolver (WooPayments does). It **cannot** do it for Stripe methods when Stripe's
  **Payment Method Configurations (PMC)** feature is active — and PMC is **on by
  default for connected Stripe accounts**.
- Net impact: high value for WooPayments / offline / standalone-gateway
  duplicates; limited value for **Stripe-vs-other** duplicates, which is a common
  real-world case.
- Good news: PMC state is **readable from core without any Stripe API call**, so
  the UI can detect it and simply not offer a resolution it can't perform.

---

## Glossary (Stripe concepts)

| Term | Stands for | What it governs |
| ---- | ---------- | --------------- |
| **UPE** | Unified Payment Element | Stripe's modern checkout; enabled methods are a *list of ids* (`card`, `klarna`, …) rendered in one element. |
| **OC** | Optimized Checkout | A **display** mode — Stripe renders its methods inside its own single element rather than as individual checkout options. |
| **PMC** | Payment Method Configurations | A **storage** mode — the enabled-method set lives **server-side in the Stripe account** (managed via Stripe's API) instead of in a local WordPress option. |

The distinction that matters: **OC is about display; PMC is about where the truth
is stored.** PMC is the one that decides whether core can change what's enabled.

---

## How the flow is built

Detection and resolution are deliberately separate extension points:

- **Detection** — `PaymentMethodDuplicatesDetector` answers *what* is duplicated.
  Core seeds a baseline (Card + the wallets); integrations contribute the rest
  through two filters (`woocommerce_payment_method_duplicate_definitions`,
  `woocommerce_payment_method_duplicate_gateway_hints`). It matches enabled
  gateway ids against method keywords and hints, and reports any canonical method
  with two or more implementations.
- **Resolution** — `PaymentMethodDuplicatesResolver` orchestrates disabling the
  losing implementations. It never mutates gateway state itself; it delegates
  *how to disable one method* to:
    - an integration-owned resolver registered via
      `woocommerce_payment_method_duplicate_resolvers`
      (`PaymentMethodDuplicateResolverInterface`), or
    - the core `GenericPaymentMethodDuplicateResolver` when the target is
      positively a standalone gateway.

The modal presents up to three steps: a Card "make WooPayments the default card
provider" step, a regular provider-choice step, and an express (wallet) step.

---

## The core constraint: who can disable a method

`GenericPaymentMethodDuplicateResolver` disables a gateway by writing
`enabled = no` on it. The orchestrator only allows this when it can **positively
prove** the gateway is a plain, standalone method — one that is neither the parent
of, nor a child in, any grouped/aggregated provider
(`PaymentMethodDuplicatesResolver::is_generic_eligible()`).

This guard exists for a good reason: core has no way to prove that a single gateway
id doesn't secretly control several methods, and a blind `enabled = no` on such a
gateway could disable more than intended, or silently no-op.

Stripe's individual method gateways (`stripe_klarna`, `stripe_affirm`, …) are
grouped as **children** of the `stripe` parent
(`StripeOptimizedCheckoutAdapter`), so they fail this test → core returns
`unsupported` and never writes. That is *correct* behaviour, not a bug — as the
Stripe source confirms below.

---

## The Stripe blocker (confirmed from source)

Investigated against `woocommerce-gateway-stripe` **v10.9.0**.

### 1. Under PMC, the enabled state is server-side

`WC_Stripe_Payment_Method_Configurations::get_upe_enabled_payment_method_ids()`
reads from the Stripe API configuration (`get_primary_configuration()`) when PMC is
enabled, and only falls back to the local option
(`upe_checkout_experience_accepted_payments`) when PMC is **off**.

> `includes/class-wc-stripe-payment-method-configurations.php` — `get_upe_enabled_payment_method_ids()`

So a core write to the local option is a **no-op** under PMC: checkout won't
reflect it.

### 2. Disabling under PMC requires an authenticated Stripe API call

The only disable path under PMC is
`update_payment_method_configuration()` → `WC_Stripe_API::update_payment_method_configurations()`,
which calls Stripe's API with the account's credentials. Core has no Stripe API
client, so **core cannot perform this**.

> `includes/class-wc-stripe-payment-method-configurations.php` — `update_payment_method_configuration()`

### 3. PMC is detectable from core — no API call needed

`WC_Stripe_Payment_Method_Configurations::is_enabled()` decides PMC from local
state only:

- the account is connected (`WC_Stripe_Helper::is_connected()`), **and**
- `stripe_settings['pmc_enabled'] !== 'no'`.

> `includes/class-wc-stripe-payment-method-configurations.php` — `is_enabled()`

This is the single, sufficient signal for "can core disable a Stripe method?" —
and it's readable the same guarded way the existing adapter already calls
`is_optimized_checkout_active()`.

### 4. PMC is ON by default for connected accounts

In `is_enabled()`, an **empty** `pmc_enabled` flag counts as **enabled** (empty =
"migration not yet attempted", treated as on). So a connected Stripe account has
PMC on unless the merchant explicitly turned it off. PMC is **off** only when the
account is disconnected or the merchant opted out.

### 5. OC depends on PMC — one-way

`WC_Stripe_Feature_Flags::is_oc_available()` returns **false unless
`pmc_enabled === 'yes'`**. So Optimized Checkout cannot exist without PMC.

> `includes/class-wc-stripe-feature-flags.php` — `is_oc_available()`

| PMC | OC | Possible? | Can core disable (local write)? |
| --- | --- | --- | --- |
| on | on | yes | **No** — state is server-side |
| on | off | yes | **No** — PMC still governs storage |
| off | on | **impossible** (OC needs PMC) | — |
| off | off | yes | **Yes** — local option is authoritative |

**Consequence:** "OC off" does *not* imply "we can disable." The correct gate is
**PMC off**, which is strictly narrower and subsumes every OC state. Any earlier
framing around OC was imprecise; PMC is the deciding fact.

---

## Impact assessment

**Where the flow is fully impactful** (disable target is not a Stripe-PMC method):

- WooPayments' own duplicated methods — WooPayments ships a resolver, so its side
  is always disable-able.
- Offline payment methods and single-method standalone gateways — handled by the
  generic core resolver.

**Where the flow is limited** (disable target *is* a Stripe method under PMC):

- Any "Klarna / Affirm / Card / … enabled through both Stripe and another provider"
  case, on a connected Stripe store with PMC on — which is the default.

Because PMC-on is the default for connected Stripe accounts, the subset of stores
where core can resolve a *Stripe* duplicate (PMC off = disconnected or explicit
opt-out) is a **minority**. If the business case for the flow rests on resolving
Stripe-vs-other duplicates, expect the reachable impact to be small until Stripe
participates.

---

## Options considered

1. **Stripe ships a resolver** (implements `PaymentMethodDuplicateResolverInterface`
   and registers via the filter). The only approach that works in **all** regimes,
   including PMC, because Stripe owns the API client. Out of scope for this effort
   (Stripe-side work is a non-goal here), but it is the clean, permanent fix.
2. **Core-only, PMC-aware:**
   - When **PMC is off**: core disables the Stripe method by rewriting the local
     UPE list and verifies the result.
   - When **PMC is on**: core detects it (item 3 above) and **does not offer** the
     doomed toggle — the row is omitted / shown as unresolvable rather than failing
     silently.
   This is the recommended core-side path. It is honest per store and never claims
   a disable it can't achieve, but it only *resolves* Stripe methods in the
   PMC-off minority.
3. **Broaden the generic resolver to blind-write grouped children.** Rejected —
   it re-introduces exactly the silent no-op the safety guard prevents (a write
   that reports success while Stripe ignores it).

---

## Safety backstop (already in place)

`PaymentMethodDuplicatesResolver::verify_disables()` re-runs detection after every
resolution and downgrades any target still present to `not_applied`. So regardless
of the path taken, the flow **never reports a false success** — a Stripe method
that couldn't actually be disabled surfaces as not-applied, not as done.

---

## Recommendation

- Make the flow **PMC-aware**: probe `WC_Stripe_Payment_Method_Configurations::is_enabled()`
  (guarded) and treat Stripe methods as disable-able only when PMC is off. Omit /
  mark unresolvable otherwise, so merchants are never offered a resolution that
  can't work.
- Calibrate expectations: position the near-term value around WooPayments, offline,
  and standalone-gateway duplicates. Stripe-vs-other resolution is gated on either
  PMC being off or Stripe shipping a resolver.
- Keep detection broad regardless — surfacing the duplicate (a warning/badge) is
  useful even where auto-resolution isn't available, as long as the UI is honest
  about what it can and cannot fix.

---

## Caveats / not yet verified

- Findings are from **reading** `woocommerce-gateway-stripe` v10.9.0 source, not
  from live testing. End-to-end confirmation needs a connected (test-mode) Stripe
  account with `pmc_enabled` toggled on and off.
- The real-world frequency of PMC-off stores is unknown here (no telemetry was
  consulted); the "minority" claim is inferred from the default-on behaviour, not
  measured.
- Line-level references above are to method names rather than fixed line numbers,
  since those shift between plugin versions.

---

## Key code references

**WooCommerce core** (`plugins/woocommerce/src/Internal/Admin/Settings/`):

- `PaymentMethodDuplicatesDetector.php` — detection, baseline, filters
- `PaymentMethodDuplicatesResolver.php` — orchestration, `is_generic_eligible()`,
  `describe_candidates()`, `verify_disables()`
- `GenericPaymentMethodDuplicateResolver.php` — core standalone-gateway disable
- `PaymentMethodDuplicateResolverInterface.php` — integration resolver contract
- `StripeOptimizedCheckoutAdapter.php` — Stripe grouping / OC display state

**Stripe plugin** (`woocommerce-gateway-stripe` v10.9.0):

- `includes/class-wc-stripe-payment-method-configurations.php` —
  `is_enabled()`, `get_upe_enabled_payment_method_ids()`,
  `update_payment_method_configuration()`
- `includes/class-wc-stripe-feature-flags.php` — `is_oc_available()`
- `includes/class-wc-stripe-helper.php` — `is_connected()`, settings accessors
