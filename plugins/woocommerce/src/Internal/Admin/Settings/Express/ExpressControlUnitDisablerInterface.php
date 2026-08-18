<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

defined( 'ABSPATH' ) || exit;

/**
 * Contract an integration implements to turn one of its own express control units off.
 *
 * Deliberately a separate interface from
 * {@see \Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodDuplicateResolverInterface}
 * rather than new methods on it. That interface is implementable by third-party code, so adding a
 * method to it would fatal every existing implementer on load. It is also the wrong shape here: it
 * disables a *gateway*, and express resolution disables a *control unit*, which may span several
 * gateways and several wallets.
 *
 * It is also separate from {@see ExpressControlUnitProviderInterface} so that describing express
 * control surfaces does not oblige an integration to own mutation, and vice versa — the same split
 * detection and resolution already use.
 *
 * Implementations must turn off exactly the unit they are asked about, using their own settings API
 * rather than writing option arrays directly: provider settings frequently carry validation, caching
 * and save-time side effects (Apple Pay domain registration, remote configuration sync) that a raw
 * write skips, and in some providers a raw write does not survive the request at all.
 *
 * @internal
 *
 * @since 11.1.0
 */
interface ExpressControlUnitDisablerInterface {

	/**
	 * Whether this implementation owns the given control unit.
	 *
	 * @param string $control_unit_id The control unit id, as reported by the provider.
	 *
	 * @return bool
	 */
	public function supports( string $control_unit_id ): bool;

	/**
	 * Turn the given control unit off.
	 *
	 * Every wallet the unit carries stops being offered as a result. That is expected: it is the
	 * coupling the merchant was shown before confirming, not an error.
	 *
	 * @param string $control_unit_id The control unit id to turn off.
	 *
	 * @return array{status: string, message?: string} `status` is `disabled` on success or `failed`
	 *         on error; `message` is an optional human-readable detail.
	 */
	public function disable( string $control_unit_id ): array;
}
