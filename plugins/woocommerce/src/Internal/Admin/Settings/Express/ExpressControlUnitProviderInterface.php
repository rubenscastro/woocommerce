<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

defined( 'ABSPATH' ) || exit;

/**
 * Contract an integration implements to describe how its express (wallet) methods are controlled.
 *
 * Detection answers *which* wallets are offered and by whom. This contract answers the question
 * detection cannot: **what happens to the merchant's other wallets if we turn one provider off.**
 * That is not derivable generically — a provider may store its wallets as separate gateways with
 * separate options and still gate them behind a single flag — so each provider describes its own
 * control units instead of core guessing from gateway ids.
 *
 * Implementations are read-only: they observe and report state, and never mutate it. Applying a
 * merchant's choice is a separate concern.
 *
 * An implementation that cannot recognise the provider's current shape must return an empty array
 * rather than a guess. Core then simply does not offer resolution for those wallets, which is
 * always safer than previewing a consequence that turns out to be wrong.
 *
 * Register an implementation through the `woocommerce_express_checkout_control_unit_providers`
 * filter. Core ships adapters for the providers it can verify; registering later lets an extension
 * replace core's view of its own product.
 *
 * @internal
 *
 * @since 11.1.0
 */
interface ExpressControlUnitProviderInterface {

	/**
	 * The express control units this provider currently exposes.
	 *
	 * Called during detection, so implementations should be cheap and must not throw — but core
	 * guards against both regardless.
	 *
	 * @return ExpressControlUnit[] The units, or an empty array when the provider is absent or its
	 *         shape is not recognised.
	 */
	public function get_control_units(): array;
}
