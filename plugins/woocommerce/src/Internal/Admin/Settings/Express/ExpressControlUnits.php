<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the express control units contributed by core adapters and extensions.
 *
 * Core ships adapters for the providers whose control surfaces it has verified, and registers them
 * through the same filter extensions use. That keeps the feature working on a stock install while
 * leaving an extension free to replace core's view of its own product by registering later.
 *
 * Every provider is called defensively: a provider that throws, returns the wrong type, or returns
 * a unit that cannot be joined to anything is skipped, and the rest still contribute. Express
 * metadata is advisory — a broken provider must never take down the settings page or, worse, cause
 * a wrong consequence to be shown to the merchant.
 *
 * @internal
 *
 * @since 11.1.0
 */
class ExpressControlUnits {

	/**
	 * Cached units for this instance, or null before they are resolved.
	 *
	 * @var ExpressControlUnit[]|null
	 */
	private ?array $units = null;

	/**
	 * All valid express control units currently exposed by registered providers.
	 *
	 * @return ExpressControlUnit[]
	 */
	public function get_units(): array {
		if ( null !== $this->units ) {
			return $this->units;
		}

		$units = array();

		foreach ( $this->get_providers() as $provider ) {
			foreach ( $this->get_units_from_provider( $provider ) as $unit ) {
				$units[] = $unit;
			}
		}

		$this->units = $units;

		return $this->units;
	}

	/**
	 * The key that owns a gateway id for duplicate-counting purposes.
	 *
	 * Express duplicate-ness must be counted per control unit, not per gateway id: a single
	 * provider can surface one wallet through several enabled gateway ids (its per-wallet split
	 * gateways plus its master gateway), and counting ids reports that single provider as a
	 * duplicate of itself.
	 *
	 * A gateway no unit claims is its own owner, so providers without an adapter still count once
	 * each and detection degrades to the previous behaviour rather than collapsing unrelated
	 * gateways together.
	 *
	 * @param string $gateway_id The enabled gateway id.
	 *
	 * @return string The owning unit id, or the gateway id itself when no unit claims it.
	 */
	public function get_owner_key( string $gateway_id ): string {
		foreach ( $this->get_units() as $unit ) {
			// First unit to claim a gateway id keeps it; a gateway belongs to exactly one unit.
			if ( in_array( $gateway_id, $unit->get_gateway_ids(), true ) ) {
				return $unit->get_id();
			}
		}

		return $gateway_id;
	}

	/**
	 * The registered control-unit providers.
	 *
	 * @return ExpressControlUnitProviderInterface[]
	 */
	private function get_providers(): array {
		$core_providers = array(
			new WooPaymentsExpressAdapter(),
			new PayPalExpressAdapter(),
		);

		/**
		 * Filters the providers that describe how express (wallet) methods are controlled.
		 *
		 * Each entry must implement
		 * {@see \Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnitProviderInterface}
		 * and report which of the provider's settings can be turned off and which wallets each of
		 * them carries. Core seeds the providers whose control surfaces it has verified; an
		 * extension can add its own, or replace core's by registering an implementation that
		 * reports the same units.
		 *
		 * @param ExpressControlUnitProviderInterface[] $providers Registered provider instances.
		 *
		 * @since 11.1.0
		 */
		$filtered = apply_filters( 'woocommerce_express_checkout_control_unit_providers', $core_providers );

		return $this->normalize_providers( $filtered, $core_providers );
	}

	/**
	 * Coerce a filtered provider list into usable provider instances.
	 *
	 * The value is third-party input, so it is treated as unknown regardless of what the filter
	 * documents. Falling back to core's own providers when the filter returns something unusable
	 * keeps a misbehaving extension from silently removing express support altogether.
	 *
	 * @param mixed                                 $filtered       The filtered value.
	 * @param ExpressControlUnitProviderInterface[] $core_providers The providers core registered.
	 *
	 * @return ExpressControlUnitProviderInterface[]
	 */
	private function normalize_providers( $filtered, array $core_providers ): array {
		if ( ! is_array( $filtered ) ) {
			return $core_providers;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $provider ) {
					return $provider instanceof ExpressControlUnitProviderInterface;
				}
			)
		);
	}

	/**
	 * The valid units a single provider exposes.
	 *
	 * @param ExpressControlUnitProviderInterface $provider The provider to query.
	 *
	 * @return ExpressControlUnit[]
	 */
	private function get_units_from_provider( ExpressControlUnitProviderInterface $provider ): array {
		try {
			/**
			 * A return value that is not an array raises a TypeError, which the catch below turns
			 * into "this provider contributes nothing". The element types are third-party input and
			 * are validated below rather than trusted from the interface's docblock.
			 *
			 * @var array<mixed> $units
			 */
			$units = $provider->get_control_units();
		} catch ( Throwable $e ) {
			return array();
		}

		return array_values(
			array_filter(
				$units,
				static function ( $unit ) {
					return $unit instanceof ExpressControlUnit && $unit->is_valid();
				}
			)
		);
	}
}
