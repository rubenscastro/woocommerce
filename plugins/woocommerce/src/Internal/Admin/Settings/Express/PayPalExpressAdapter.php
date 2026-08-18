<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

use Throwable;
use WC_Payment_Gateway;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how PayPal Payments controls its Apple Pay and Google Pay wallets.
 *
 * The mirror image of {@see WooPaymentsExpressAdapter}: PayPal registers each wallet as its own
 * `WC_Payment_Gateway` (`ppcp-applepay`, `ppcp-googlepay`) reading its own standard
 * `woocommerce_{id}_settings['enabled']` option, and its read paths are disjoint — each wallet has
 * its own script bundle gated on its own flag. Turning one off leaves the other untouched, so each
 * wallet gets its own unit and choosing another provider for one wallet has no consequence for the
 * other.
 *
 * Enabled state is read from the registered gateway object rather than the raw option, so it
 * reflects whatever PayPal's own configuration logic has settled on. On a store with no connected
 * PayPal account those gateways report disabled regardless of what is written to the option, and
 * this adapter follows that rather than contradicting it.
 *
 * Every piece of PayPal knowledge in express detection lives in this file, and it only reads state.
 *
 * @internal
 *
 * @since 11.1.0
 */
class PayPalExpressAdapter implements ExpressControlUnitProviderInterface, ExpressControlUnitDisablerInterface {

	/**
	 * The plugin slug of PayPal Payments.
	 *
	 * @var string
	 */
	private const PROVIDER_SLUG = 'woocommerce-paypal-payments';

	/**
	 * PayPal's own settings REST route for payment methods.
	 *
	 * @var string
	 */
	private const SETTINGS_ROUTE = 'wc/v3/wc_paypal/payment';

	/**
	 * The PayPal smart-button gateway the wallets are served through.
	 *
	 * @var string
	 */
	private const BUTTON_GATEWAY_ID = 'ppcp-gateway';

	/**
	 * Map of PayPal wallet gateway id to the canonical wallet it provides.
	 *
	 * @var array<string, string>
	 */
	private const WALLET_GATEWAYS = array(
		'ppcp-applepay'  => ExpressControlUnit::WALLET_APPLE_PAY,
		'ppcp-googlepay' => ExpressControlUnit::WALLET_GOOGLE_PAY,
	);

	/**
	 * The PayPal express control units, one per wallet.
	 *
	 * Returns an empty array when PayPal is absent, and skips any wallet gateway that is not
	 * registered, so an unrecognised version yields no units rather than a guess.
	 *
	 * @return ExpressControlUnit[]
	 */
	public function get_control_units(): array {
		try {
			if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
				return array();
			}

			$gateways = WC()->payment_gateways()->payment_gateways();
			$units    = array();

			foreach ( self::WALLET_GATEWAYS as $gateway_id => $wallet_id ) {
				$gateway = $gateways[ $gateway_id ] ?? null;

				if ( ! $gateway instanceof WC_Payment_Gateway ) {
					continue;
				}

				$units[] = new ExpressControlUnit(
					'ppcp:' . $wallet_id,
					self::PROVIDER_SLUG,
					'PayPal',
					array( $gateway_id ),
					array( $wallet_id ),
					filter_var( $gateway->enabled, FILTER_VALIDATE_BOOLEAN ),
					true,
					// `BlocksPaymentMethod::is_active()` defers to the PayPal gateway, so the wallets
					// go when the smart button does. Notably *not* a card dependency — PayPal's card
					// methods are separate gateways, so consolidating cards never costs its wallets.
					array( self::BUTTON_GATEWAY_ID )
				);
			}

			return $units;
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
		return in_array( $control_unit_id, $this->unit_ids(), true );
	}

	/**
	 * Turn one PayPal wallet off.
	 *
	 * Goes through PayPal's own settings REST route, dispatched internally. Two alternatives were
	 * rejected:
	 *
	 * - writing `woocommerce_ppcp-{wallet}_settings` directly. PayPal's settings model prunes unknown
	 *   keys on read, re-saves gateway state from its own in-memory objects, and fires a store-sync
	 *   action on save. On a store whose PayPal account is not connected, a direct write is reverted
	 *   within the same request — verified against the database — so it is not merely impolite, it
	 *   does not work.
	 * - reaching into PayPal's DI container for its settings service. That would make core depend on
	 *   another plugin's internal service ids and class names; core touches other plugins only
	 *   through objects WooCommerce itself hands out, or through their public REST surface.
	 *
	 * @param string $control_unit_id The control unit id.
	 *
	 * @return array{status: string, message?: string}
	 */
	public function disable( string $control_unit_id ): array {
		if ( ! $this->supports( $control_unit_id ) ) {
			return array(
				'status'  => 'failed',
				'message' => 'Unknown PayPal express control unit.',
			);
		}

		$gateway_id = array_search( $control_unit_id, $this->unit_ids(), true );

		if ( ! is_string( $gateway_id ) ) {
			return array(
				'status'  => 'failed',
				'message' => 'Unknown PayPal express control unit.',
			);
		}

		try {
			$request = new WP_REST_Request( 'POST', '/' . self::SETTINGS_ROUTE );
			$request->set_param( $gateway_id, array( 'enabled' => false ) );

			$response = rest_do_request( $request );

			if ( $response->is_error() ) {
				$error = $response->as_error();

				return array(
					'status'  => 'failed',
					'message' => $error instanceof WP_Error ? $error->get_error_message() : 'The PayPal settings request failed.',
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

	/**
	 * Map of wallet gateway id to the control unit id this adapter reports for it.
	 *
	 * @return array<string, string>
	 */
	private function unit_ids(): array {
		$ids = array();

		foreach ( self::WALLET_GATEWAYS as $gateway_id => $wallet_id ) {
			$ids[ $gateway_id ] = 'ppcp:' . $wallet_id;
		}

		return $ids;
	}
}
