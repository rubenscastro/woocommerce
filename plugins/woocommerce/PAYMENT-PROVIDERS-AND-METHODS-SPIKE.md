# Payment Providers & Methods — Technical Spike Findings

This was an exploratory spike, not production-ready code. It answers whether core can ship a
grouped Payment providers view and a separate, reorderable Payment methods page **using the
integrations that exist today**, with no provider-side changes. Everything below is on branch
[`payment-methods-listing-and-grouping`](https://github.com/rubenscastro/woocommerce/tree/payment-methods-listing-and-grouping)
(commit `c9f0a7a`); duplicate detection/resolution was explored separately and is deliberately
excluded from this branch (see the deduplication section below).

Verification note: findings marked *verified* were exercised against a local store with WooPayments,
Stripe, PayPal, Square and Mollie active. Findings marked *source-inspection* come from reading the
provider code. Assumptions still needing engineering validation are called out as such.

## TL;DR

- Gateways can be grouped by extension reliably enough for the proposed Providers UI, for the set we
  tested — but the grouping key is a heuristic, not a contract.
- Checkout payment methods can be discovered in wp-admin by reusing the existing Checkout Block
  registry; no new registry was needed.
- Providers and methods are genuinely different surfaces, and the spike keeps them separate: the
  saved order is a flat list of method identities, never provider/group rows.
- A canonical method order can be persisted (option `woocommerce_payment_method_order`) and it drives
  **both** checkouts — live for the Checkout Block, and as a save-time projection for Classic.
- The main assumption we still need to validate is that a method's Checkout Block registry `name`
  equals its WC gateway id. It held for every method actually selectable at checkout in what we tested,
  but the platform doesn't guarantee it.
- Providers that render their own methods (Stripe with Optimized Checkout) can't be introspected from
  a settings page and need a per-provider adapter; only Stripe's is implemented.
- The page represents configured checkout capabilities, not a simulation of one shopper's checkout.

## What was prototyped

Two vertical slices on the two Payments settings surfaces.

**Payment providers** — gateway rows that belong to the same extension are collapsed under one parent
row. The grouping is a pure front-end transform over the provider list the page already loads
([`groupProvidersByExtension`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/group-providers-by-extension.ts#L167)),
rendered through the **existing** list component
([`PaymentGatewayList`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/components/payment-gateway-list/payment-gateway-list.tsx#L112))
plus a new
[`PaymentGatewayGroupItem`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/components/payment-gateway-group-item/payment-gateway-group-item.tsx#L69)).
It is not a DataViews rewrite — a DataViews providers page was explored in a separate throwaway PoC
that is not on this branch. Grouping writes nothing and reorders nothing; expand/collapse is
in-memory only.

**Payment methods** — a new page under Settings → Payments that lists the individual checkout methods,
supports drag-and-drop reordering, persists the order, and applies it to checkout. The page lives in
[`settings-payments-methods.tsx`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/settings-payments-methods.tsx);
its server side is
[`BlocksPaymentMethodsSpikeController`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/BlocksPaymentMethodsSpikeController.php).

## Architecture

```text
Checkout integrations (Blocks payment-method scripts)
        │  loaded in wp-admin on the methods section
        ▼
Checkout Block registry (getPaymentMethods)                 gateway list (existing store)
        │                                                            │
        ▼                                                            ▼
Payment methods page                                        Payment providers page
   drag-to-order                                               grouped by extension
        │                                                     (presentation only)
        ▼
option woocommerce_payment_method_order  (canonical order)
        ├──────────────► Checkout Block  (live: Blocks/Payments/Api paymentMethodSortOrder)
        └──────────────► Classic checkout (save-time projection → woocommerce_gateway_order)
```

The canonical order is a single option. The Checkout Block reads it live on every cart/checkout data
enqueue
([`Api::get_payment_method_sort_order`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Blocks/Payments/Api.php#L112)).
Classic is fed differently — the saved order is projected once, at save time, into WooCommerce's
existing `woocommerce_gateway_order` option
([`PaymentsProviders::PROVIDERS_ORDER_OPTION`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders.php#L78),
from
[`Payments.php`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/Payments.php#L349)).
That mechanism difference matters for correctness (see finding 4).

## Key findings

### 1. Existing Checkout registrations can be reused in admin

The active Blocks payment-method scripts are enqueued in wp-admin on the methods section
([`get_all_active_payment_method_script_dependencies`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/BlocksPaymentMethodsSpikeController.php#L95)),
so they register themselves exactly as they do at checkout, and the page reads the same client registry
via `getPaymentMethods()`. No parallel registry was built. *Verified.*

The caveat is that these scripts are written for the checkout page and can fail silently elsewhere.
Square's Cash App Pay never appeared in one run: its script read a global it never declared as a
dependency (something else loads it first at checkout), and WooCommerce's storefront-only guard means a
broken dependency in admin produces nothing at all, with no surfaced error. The spike forces the block
scripts ahead of the admin bundle to work around the ordering, but the general risk — third-party
checkout code running in an admin context — is a real lifecycle and dependency risk that needs
engineering review, not something the spike fully solved.

### 2. Provider and payment method are different concepts

The Providers page lists plugins/suites ("Stripe", "WooPayments"); checkout orders individual methods
(card, iDEAL, Bancontact). The spike keeps these separate — the persisted order is a flat list of real
method identities
([`serializePaymentMethodOrder`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/settings-payments-methods.tsx#L438)),
never the provider/group rows grouping produces.

The registry is thin: a registered method exposes a `name`, a React `label`, an `ariaLabel` string, and
an optional `icons` array — nothing links it to its provider. WooPayments sets `ariaLabel` to
"WooPayments" for every method it registers and carries the real method name inside `label` (a React
node unsafe to render outside checkout). The spike renders `label` inside an error boundary and falls
back to `ariaLabel || name`
([`PaymentMethodName`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/settings-payments-methods.tsx#L124)).
The `ariaLabel`-is-the-provider-name behavior is an observation across the providers we tested, not a
contract the type enforces.

### 3. Provider grouping is possible, but not universal

Grouping keys on `_suggestion_id`, falling back to `plugin.slug`, then `id`
([`groupKeyOf`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/group-providers-by-extension.ts#L69)),
and only groups gateway rows with 2+ members — so PayPal, Square and Amazon Pay collapse while a
single-gateway extension like WooPayments stays a normal row. *Verified against the tested set.* The
parent name/logo come from the matching suggestion, with a humanized-slug fallback when the suggestion
isn't available client-side (which produces "Paypal Payments" for `woocommerce-paypal-payments` — a
known rough edge).

Two cases required special handling in the spike:

- The "one Manage button per group" rule works only when a group's gateways share a settings screen,
  judged by `page|tab|section`
  ([`sharedSettingsUrlOf`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/group-providers-by-extension.ts#L118)).
  PayPal's sub-gateways each link to their own URL, so it needed a narrow, PayPal-scoped exception that
  points the group's Manage at `ppcp-gateway`
  ([`paypalSharedSettingsUrl`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/group-providers-by-extension.ts#L134)).
- Stripe with Optimized Checkout renders its methods inside its own element and decides availability at
  runtime, so its methods are not individually addressable. Detecting that state is not possible from a
  settings page — Stripe gates the flag behind `is_checkout()` — so the spike asks the gateway directly
  ([`is_optimized_checkout_active`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/StripeOptimizedCheckoutAdapter.php#L94)),
  which is hardcoded gateway-specific knowledge
  ([`PARENT_GATEWAY_ID = 'stripe'`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/StripeOptimizedCheckoutAdapter.php#L42)),
  wired as the single adapter the controller knows about.

So grouping is presentation only — fine for extensions with several independent gateways, not for
providers that render their own methods.

### 4. A canonical payment-method order is feasible

The order persists to option `woocommerce_payment_method_order`
([`PaymentMethodsOrder::save`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/PaymentMethodsOrder.php#L116))
via a REST route
([`PaymentsRestController`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/PaymentsRestController.php#L125)),
and Save only clears its dirty state on a successful response. *Verified — this supersedes an earlier
phase where Save was inert.* Stored entries are the registry `name`, which the code documents as equal
to the WC gateway id for any selectable method — the reason one list can feed both checkouts
([`PaymentMethodsOrder`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/PaymentMethodsOrder.php#L16)).
Disabled/stale entries are filtered out and unknown methods are handled without breaking checkout
([`get_for_checkout`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/PaymentMethodsOrder.php#L82)).

Two things still need validation: the identity assumption above (the whole basis for cross-checkout
sync), and the Classic projection — since Classic is written once at save time, if gateways change
before the next save its order can drift from the canonical option.

### 5. Admin representation is not shopper availability

The list is filtered to enabled gateways
([`buildRows`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/settings-payments-methods.tsx#L374)),
which reproduces the cart-membership filter checkout applies — in one run 19 of 35 registered methods
were correctly dropped because their gateway was disabled. What it does *not* reproduce is each method's
own runtime `canMakePayment` check (country, currency, cart contents). That enabled-gateway filter is in
the code; the country/currency/cart limit is a reasoned consequence of there being no cart, not a claim
the code makes. The page shows what the store is configured to offer, not what one cart resolves to.

## Provider-specific findings

Only representation/ordering implications; not an exhaustive audit.

| Provider | Representation | Ordering implication | Caveat |
| --- | --- | --- | --- |
| WooPayments | one row on the Providers page (not grouped), though it exposes several checkout methods | its methods ordered like any others; `name`==id holds | method labels all read "WooPayments" via `ariaLabel` |
| Stripe, OC off | nested children under a Stripe parent (Card injected as a child) | children individually orderable | Card is the master `stripe` gateway, not a separate `stripe_card` |
| Stripe, OC on | one row + "Optimized Checkout" badge, methods hidden | not orderable within; only "move Stripe to top" | state only knowable by asking the gateway directly |
| Square | multiple gateways → grouped | orderable | Cash App Pay failed to register in admin (script assumed checkout) |
| PayPal | multiple gateways → grouped | orderable | needed a PayPal-scoped rule to show one Manage button |
| Offline (BACS, cheque, COD) | kept in WooCommerce's curated order | left in the platform's default grouping | not part of the reorder surface |

## Deliberately explored but would not ship now: deduplication

Duplicate detection/resolution was explored on a sibling branch because it shares this normalization
problem — "which enabled things are really the same method?". It produced useful architecture (a
provider-agnostic detector, and a separation between detecting a duplicate and owning the mutation to
resolve it), but resolution introduces substantially more provider-specific behavior than listing or
reordering. The clearest evidence is Stripe: under Payment Method Configurations, the enabled state
lives server-side and core cannot disable a method with a local write at all — so core cannot safely
mutate every provider's methods, only represent them. That is why duplicate identification/resolution
is not part of this proposal. Details in the
[deduplication spike report](https://github.com/rubenscastro/woocommerce/blob/2f744e4411ea79c79c94412944e0839b5f2c773c/plugins/woocommerce/src/Internal/Admin/Settings/PAYMENT-METHOD-DEDUPLICATION-SPIKE.md)
(branch `payment-methods-duplicate-followups`).

## Limitations and engineering questions

Known limitations (accepted, understood):

- Method identity is the registry `name`, relied on equaling the gateway id. Convention, not contract.
- Classic ordering is a save-time projection into `woocommerce_gateway_order`, not a live filter.
- Aggregating providers need a per-provider adapter; only Stripe OC is implemented, and it is hardcoded.
- No canonical provider brand name exists; grouping falls back to a humanized plugin slug.
- Third-party block scripts loaded in admin can fail silently (the Square Cash App case).

Needs engineering validation:

- Is the Checkout registry `name` a sufficiently stable canonical identity across providers and versions
  to persist an order against?
- Is the save-time Classic projection acceptable long-term, or does Classic need a live filter to stay
  in sync?
- Is the `_suggestion_id`/slug grouping heuristic robust beyond the tested set? There is no signal for a
  plugin that ships multiple gateways it wants shown as separate providers.
- Are there lifecycle/performance concerns with loading Checkout integration scripts in this admin
  context on every visit?

## What this spike suggests we can ship without provider changes

1. Group related gateway rows on the Payment providers page where the extension can be identified
   confidently, rendered through the existing list components.
2. A separate Payment methods page reachable from Payments.
3. List the configured, first-class checkout methods by reusing the Checkout Block registry.
4. Reorder those methods with drag-and-drop.
5. Persist a canonical order and apply it to the Checkout Block live and to Classic via projection.
6. Leave provider-owned/aggregated ordering alone where core can't control it (Stripe OC as one row).
7. Degrade gracefully when a method offers only an id (name/logo fallbacks).

Explicitly not required: no new provider registration API, no coordinated provider releases, no
duplicate resolution, and no attempt to reproduce a specific shopper's availability.

## Spike code vs proposed direction

Conclusions we believe hold: the two-surface split (providers grouped for presentation, methods as the
ordering unit); that a single canonical order can feed both checkouts; and that extension grouping is
viable for extensions exposing independent gateways.

Scaffolding used only to prove them, which core engineering should expect to replace: the
`BlocksPaymentMethodsSpikeController` enqueue-in-admin approach (needs a proper lifecycle review before
it's a supported pattern); the Stripe adapter (a hardcoded, gateway-specific bridge standing in for a
real aggregating-provider pattern); the PayPal Manage exception; brand-name humanization; and
bundled-icon-by-id-convention matching. The branch is a spike, not a PR candidate.

## Feedback wanted

1. Does reusing the Checkout Block registry in admin look reasonable for a production implementation, or
   is the script-lifecycle risk a blocker?
2. Any concerns with persisting order against the registry `name`/gateway-id identity assumption?
3. Are there cases where grouping gateways by `_suggestion_id`/slug would misrepresent actual behavior?
4. Which parts of this — spike controller, Stripe adapter, Classic projection — would you rebuild first?
5. Is there anything here you consider to fundamentally require provider-side work rather than a
   core-only implementation?

## References

- Branch: [`payment-methods-listing-and-grouping`](https://github.com/rubenscastro/woocommerce/tree/payment-methods-listing-and-grouping) (`c9f0a7a`)
- Grouping: [`group-providers-by-extension.ts`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/group-providers-by-extension.ts)
- Methods page: [`settings-payments-methods.tsx`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/client/admin/client/settings-payments/settings-payments-methods.tsx), [`BlocksPaymentMethodsSpikeController.php`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/BlocksPaymentMethodsSpikeController.php)
- Order model: [`PaymentMethodsOrder.php`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Internal/Admin/Settings/PaymentMethodsOrder.php), [`Blocks/Payments/Api.php`](https://github.com/rubenscastro/woocommerce/blob/c9f0a7aafcd5f40d7ae7c350fadd77c1ea776368/plugins/woocommerce/src/Blocks/Payments/Api.php)
- Deduplication (supporting research): [`PAYMENT-METHOD-DEDUPLICATION-SPIKE.md`](https://github.com/rubenscastro/woocommerce/blob/2f744e4411ea79c79c94412944e0839b5f2c773c/plugins/woocommerce/src/Internal/Admin/Settings/PAYMENT-METHOD-DEDUPLICATION-SPIKE.md) on branch `payment-methods-duplicate-followups`
