<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use ReflectionClass;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe-specific adapter for the "Payment methods" settings section.
 *
 * Discovery of payment methods stays generic — it comes from the Checkout Block client registry.
 * This class answers only a presentation question that the registry cannot: whether Stripe's
 * individual methods should be listed at all.
 *
 * Stripe has two modes. With Optimized Checkout off it exposes each method as a first-class
 * Checkout Block option. With Optimized Checkout on it renders them inside its own block, where
 * which ones actually appear depends on runtime conditions Stripe owns — so listing them in
 * wp-admin would be misleading.
 *
 * That state is not observable from the payment method scripts here: Stripe gates the flag they
 * receive behind `is_valid_optimized_checkout_page()`, which returns `is_checkout()` and is
 * therefore always false in wp-admin. The scripts always behave as if the feature were off. The
 * gateway does expose a broader, page-independent check — `is_optimized_checkout_active()` —
 * which returns the real store-level state outside checkout, so **no workaround for the
 * `is_checkout()` guard was necessary**; this adapter simply calls that method.
 *
 * Every piece of Stripe knowledge in this feature lives in this file. It is deliberately not a
 * provider abstraction: it exists to represent Stripe's two modes accurately and nothing more.
 *
 * @internal
 */
class StripeOptimizedCheckoutAdapter {

	/**
	 * The gateway id of the Stripe parent gateway.
	 *
	 * @var string
	 */
	private const PARENT_GATEWAY_ID = 'stripe';

	/**
	 * Describe how Stripe's payment methods should be grouped on the settings page.
	 *
	 * Keyed by the parent gateway id so the client can render it without knowing which provider
	 * it belongs to. Returns an empty array when Stripe is absent or cannot be inspected, in which
	 * case the page falls back to listing every registered method flat.
	 *
	 * @return array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}>
	 */
	public function get_grouped_providers(): array {
		try {
			$gateways       = WC()->payment_gateways()->payment_gateways();
			$parent_gateway = $gateways[ self::PARENT_GATEWAY_ID ] ?? null;

			if ( null === $parent_gateway ) {
				return array();
			}

			$child_gateway_ids = $this->get_sibling_gateway_ids( $parent_gateway, $gateways );

			if ( empty( $child_gateway_ids ) ) {
				return array();
			}

			$optimized_checkout = $this->is_optimized_checkout_active( $parent_gateway );

			return array(
				self::PARENT_GATEWAY_ID => array(
					'childGatewayIds'   => $child_gateway_ids,
					'showChildren'      => ! $optimized_checkout,
					'optimizedCheckout' => $optimized_checkout,
					'settingsUrl'       => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::PARENT_GATEWAY_ID ),
				),
			);
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * Whether Stripe is currently rendering its methods inside its own block.
	 *
	 * Defaults to false — i.e. the methods are listed — when the gateway does not expose the
	 * check, so that an unrecognised Stripe version degrades to the more informative view rather
	 * than hiding methods that are in fact first-class options.
	 *
	 * @param object $gateway The Stripe parent gateway.
	 *
	 * @return bool
	 */
	private function is_optimized_checkout_active( object $gateway ): bool {
		if ( ! method_exists( $gateway, 'is_optimized_checkout_active' ) ) {
			return false;
		}

		return (bool) $gateway->is_optimized_checkout_active();
	}

	/**
	 * The ids of the other gateways shipped by the same plugin as the given gateway.
	 *
	 * Resolved by comparing the plugin directory each gateway class lives in, rather than by
	 * matching an id prefix, so a renamed or unconventionally named sub-gateway is still grouped.
	 *
	 * @param object   $parent_gateway The parent gateway.
	 * @param object[] $gateways       All payment gateways, keyed by id.
	 *
	 * @return string[]
	 */
	private function get_sibling_gateway_ids( object $parent_gateway, array $gateways ): array {
		$parent_plugin = $this->get_owning_plugin( $parent_gateway );

		if ( '' === $parent_plugin ) {
			return array();
		}

		$sibling_ids = array();

		foreach ( $gateways as $gateway_id => $gateway ) {
			if ( self::PARENT_GATEWAY_ID === $gateway_id ) {
				continue;
			}

			if ( $this->get_owning_plugin( $gateway ) === $parent_plugin ) {
				$sibling_ids[] = (string) $gateway_id;
			}
		}

		return $sibling_ids;
	}

	/**
	 * The plugin directory slug that owns a gateway's class.
	 *
	 * @param object $gateway The gateway to inspect.
	 *
	 * @return string Empty string when the owning plugin cannot be determined.
	 */
	private function get_owning_plugin( object $gateway ): string {
		$file = ( new ReflectionClass( $gateway ) )->getFileName();

		return preg_match( '#/plugins/([^/]+)/#', (string) $file, $matches ) ? $matches[1] : '';
	}
}
