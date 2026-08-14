<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Core fallback resolver for a payment method that is a plain, standalone `WC_Payment_Gateway`.
 *
 * For a gateway that *is* the payment method one-to-one — an offline method, a single-method classic
 * gateway — disabling the method is exactly setting the gateway's `enabled` flag to `no`. This resolver
 * does only that.
 *
 * It is deliberately **not** an automatic fallback for every unmatched id. The orchestrator
 * ({@see PaymentMethodDuplicatesResolver}) invokes it only after positively establishing, from existing
 * representation metadata, that the target is a standalone gateway that is not part of any
 * aggregated/grouped provider. Core has no signal to prove that a single gateway id does not hide
 * several methods, so when eligibility cannot be positively established the orchestrator returns
 * `unsupported` and this resolver is never called — it never guesses.
 *
 * @internal
 *
 * @since 11.1.0
 */
class GenericPaymentMethodDuplicateResolver implements PaymentMethodDuplicateResolverInterface {

	/**
	 * Whether the id is a registered `WC_Payment_Gateway`.
	 *
	 * This is only the gateway-exists half of eligibility; the orchestrator additionally requires that
	 * the gateway is not part of any aggregated/grouped provider before choosing this resolver.
	 *
	 * @param string $gateway_id The enabled gateway id to disable.
	 *
	 * @return bool
	 */
	public function supports( string $gateway_id ): bool {
		return $this->get_gateway( $gateway_id ) instanceof WC_Payment_Gateway;
	}

	/**
	 * A standalone gateway this resolver handles is always disableable by toggling its `enabled` flag.
	 *
	 * @param string $gateway_id The enabled gateway id.
	 *
	 * @return bool
	 */
	public function can_disable( string $gateway_id ): bool {
		return $this->supports( $gateway_id );
	}

	/**
	 * Disable the standalone gateway by setting its `enabled` flag to `no`.
	 *
	 * @param string $gateway_id The enabled gateway id to disable.
	 *
	 * @return array{status: string, message?: string}
	 */
	public function disable( string $gateway_id ): array {
		$gateway = $this->get_gateway( $gateway_id );

		if ( ! $gateway instanceof WC_Payment_Gateway ) {
			return array(
				'status'  => 'failed',
				'message' => 'The gateway is not registered.',
			);
		}

		$gateway->update_option( 'enabled', 'no' );
		$gateway->enabled = 'no';

		return array( 'status' => 'disabled' );
	}

	/**
	 * The registered gateway for an id, or null when unavailable.
	 *
	 * @param string $gateway_id The gateway id.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	private function get_gateway( string $gateway_id ): ?WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return null;
		}

		$gateway = WC()->payment_gateways()->payment_gateways()[ $gateway_id ] ?? null;

		return $gateway instanceof WC_Payment_Gateway ? $gateway : null;
	}
}
