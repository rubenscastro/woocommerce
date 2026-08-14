<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Contract an integration implements to disable one of its own duplicated payment methods.
 *
 * Detection (see {@see PaymentMethodDuplicatesDetector}) answers *what* is duplicated; this contract
 * answers *how* to disable one specific implementation. The two are deliberately separate extension
 * points: an integration can contribute duplicate metadata without owning mutation, and vice versa.
 *
 * WooCommerce core owns the orchestration ({@see PaymentMethodDuplicatesResolver}); an integration
 * registers a resolver through the `woocommerce_payment_method_duplicate_resolvers` filter so it can
 * disable an individual method the way its own storage model requires (e.g. WooPayments' split
 * gateways, Stripe's aggregated enabled-method list) instead of core writing gateway options it does
 * not understand.
 *
 * A resolver must never disable the whole provider or any method other than the one it is asked to
 * disable.
 *
 * @internal
 *
 * @since 11.1.0
 */
interface PaymentMethodDuplicateResolverInterface {

	/**
	 * Whether this resolver owns the disabling of the given gateway id.
	 *
	 * @param string $gateway_id The enabled gateway id to disable (as reported by the detector).
	 *
	 * @return bool True if this resolver should handle the id.
	 */
	public function supports( string $gateway_id ): bool;

	/**
	 * Whether the given payment method can be disabled individually.
	 *
	 * A provider may keep one of its methods mandatory while it is active — WooPayments, for example,
	 * requires Card and cannot disable it on its own without disabling the whole provider. Such a method
	 * must never be targeted for disable, and when it participates in a duplicate it is the only valid
	 * implementation to keep. Resolvers report this so core can present a single valid choice and refuse
	 * an unsupported mutation regardless of client state.
	 *
	 * @param string $gateway_id The enabled gateway id.
	 *
	 * @return bool True if the method can be individually disabled.
	 */
	public function can_disable( string $gateway_id ): bool;

	/**
	 * Disable the single payment method identified by the gateway id.
	 *
	 * Must affect only that method — never the provider itself or its other methods.
	 *
	 * @param string $gateway_id The enabled gateway id to disable.
	 *
	 * @return array{status: string, message?: string} A structured result. `status` is `disabled` on
	 *         success or `failed` on error; `message` is an optional human-readable detail.
	 */
	public function disable( string $gateway_id ): array;
}
