<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how WooPayments controls its Apple Pay and Google Pay wallets.
 *
 * WooPayments exposes the two wallets as separate gateways (`woocommerce_payments_apple_pay` and
 * `woocommerce_payments_google_pay`) with separate option arrays, so from the outside they look
 * independently controllable. They are not. `WC_Payment_Gateway_WCPay::is_payment_request_enabled()`
 * returns true when *either* wallet gateway is enabled, and that single value is published to the
 * client as `isPaymentRequestEnabled`, which is the one flag guarding both express registrations.
 *
 * Verified on WooPayments 11.0.0: disabling only the Apple Pay gateway leaves
 * `is_payment_request_enabled()` true, so WooPayments keeps offering Apple Pay. Stopping it
 * requires turning both wallets off — which is why this adapter reports a single unit carrying
 * both, rather than one unit per wallet.
 *
 * Every piece of WooPayments knowledge in express detection lives in this file, and it only reads
 * state.
 *
 * @internal
 *
 * @since 11.1.0
 */
class WooPaymentsExpressAdapter implements ExpressControlUnitProviderInterface, ExpressControlUnitDisablerInterface {

	/**
	 * The id of the single unit that carries both WooPayments wallets.
	 *
	 * @var string
	 */
	private const UNIT_ID = 'woopayments:wallets';

	/**
	 * The plugin slug of WooPayments.
	 *
	 * @var string
	 */
	private const PROVIDER_SLUG = 'woocommerce-payments';

	/**
	 * The gateway ids through which WooPayments surfaces its express wallets.
	 *
	 * The master gateway is included deliberately: WooPayments surfaces express through it too, so
	 * a hint that resolves the master gateway to a wallet must collapse into this same unit rather
	 * than counting as a second implementation.
	 *
	 * @var string[]
	 */
	private const GATEWAY_IDS = array(
		'woocommerce_payments',
		'woocommerce_payments_apple_pay',
		'woocommerce_payments_google_pay',
	);

	/**
	 * The id of the WooPayments master gateway, which exposes the express flag.
	 *
	 * @var string
	 */
	private const MASTER_GATEWAY_ID = 'woocommerce_payments';

	/**
	 * The WooPayments express control units.
	 *
	 * Returns an empty array when WooPayments is absent or does not expose the flag this adapter
	 * reads, so an unrecognised version means the wallets are simply not offered for resolution.
	 *
	 * @return ExpressControlUnit[]
	 */
	public function get_control_units(): array {
		try {
			if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
				return array();
			}

			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = $gateways[ self::MASTER_GATEWAY_ID ] ?? null;

			// The flag is read off the registered master gateway rather than through the plugin's
			// entry-point class, so this file takes no compile-time dependency on WooPayments.
			if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'is_payment_request_enabled' ) ) {
				return array();
			}

			return array(
				new ExpressControlUnit(
					self::UNIT_ID,
					self::PROVIDER_SLUG,
					'WooPayments',
					self::GATEWAY_IDS,
					array( ExpressControlUnit::WALLET_APPLE_PAY, ExpressControlUnit::WALLET_GOOGLE_PAY ),
					(bool) $gateway->is_payment_request_enabled(),
					true,
					// Both wallets map to the same `card_payments` capability as Card and are served
					// through the master gateway, so they cannot outlive it.
					array( self::MASTER_GATEWAY_ID )
				),
			);
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * Whether this adapter owns the given control unit.
	 *
	 * @param string $control_unit_id The control unit id.
	 *
	 * @return bool
	 */
	public function supports( string $control_unit_id ): bool {
		return self::UNIT_ID === $control_unit_id;
	}

	/**
	 * Turn WooPayments' wallet unit off.
	 *
	 * Both wallet gateways are disabled, because that is what the unit *is*: `is_payment_request_enabled()`
	 * ORs them, so leaving either one on keeps both wallets rendering. Disabling only the wallet the
	 * merchant reassigned would silently do nothing — the exact failure the control-unit model exists
	 * to prevent.
	 *
	 * This mirrors what WooPayments' own settings controller does for `is_payment_request_enabled`
	 * (it calls `enable()`/`disable()` on both gateways), using the same gateway API rather than
	 * writing the option arrays — a raw write to the Apple Pay option triggers WooPayments' domain
	 * registration hook and an outbound API call.
	 *
	 * @param string $control_unit_id The control unit id.
	 *
	 * @return array{status: string, message?: string}
	 */
	public function disable( string $control_unit_id ): array {
		if ( ! $this->supports( $control_unit_id ) ) {
			return array(
				'status'  => 'failed',
				'message' => 'Unknown WooPayments express control unit.',
			);
		}

		try {
			if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
				return array(
					'status'  => 'failed',
					'message' => 'Payment gateways are not available.',
				);
			}

			$gateways = WC()->payment_gateways()->payment_gateways();
			$disabled = 0;

			foreach ( array( 'woocommerce_payments_apple_pay', 'woocommerce_payments_google_pay' ) as $gateway_id ) {
				$gateway = $gateways[ $gateway_id ] ?? null;

				if ( is_object( $gateway ) && method_exists( $gateway, 'disable' ) ) {
					$gateway->disable();
					++$disabled;
				}
			}

			if ( 0 === $disabled ) {
				return array(
					'status'  => 'failed',
					'message' => 'No WooPayments wallet gateway could be disabled.',
				);
			}

			return array( 'status' => 'disabled' );
		} catch ( Throwable $e ) {
			return array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			);
		}
	}
}
