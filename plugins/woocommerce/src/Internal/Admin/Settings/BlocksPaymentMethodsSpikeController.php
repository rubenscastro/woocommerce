<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Api as BlocksPaymentsApi;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Throwable;
use WC_Settings_Payment_Gateways;
use _WP_Dependency;

defined( 'ABSPATH' ) || exit;

/**
 * Controller for the "Payment methods" section of the Payments settings page.
 *
 * This is an architectural spike. It makes the individual Checkout Block payment methods
 * discoverable in wp-admin by reusing the existing Blocks payment method infrastructure
 * instead of building a parallel server-side registry:
 *
 * 1. `PaymentMethodRegistry::get_all_active_payment_method_script_dependencies()` gives us the
 *    script handles of every active payment method integration (using its admin-specific
 *    handles, since `is_admin()` is true here).
 * 2. Those scripts are enqueued on this settings section, so they run and call
 *    `registerPaymentMethod()` exactly as they do on the Cart/Checkout blocks.
 * 3. The React section then reads the resulting client-side registry via `getPaymentMethods()`.
 *
 * @internal
 */
class BlocksPaymentMethodsSpikeController {

	/**
	 * The handle of the WooCommerce Admin script that mounts the Payments settings React app.
	 *
	 * @var string
	 */
	private const ADMIN_SCRIPT_HANDLE = 'wc-admin-settings-embed';

	/**
	 * The `wcSettings` key the gateway-to-plugin map is published under.
	 *
	 * @var string
	 */
	private const ASSET_DATA_KEY = 'blocksPaymentMethodsSpike';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// WCAdminAssets::enqueue_assets() runs at priority 15, so the admin bundle is already
		// registered (and enqueued) by the time this runs.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_payment_method_scripts' ), 20 );
	}

	/**
	 * Enqueue the Checkout Block payment method scripts on the Payment methods settings section.
	 *
	 * Everything here is scoped to that one section, so no other admin screen and no front-end
	 * request is affected.
	 */
	public function enqueue_payment_method_scripts(): void {
		if ( ! $this->is_payment_methods_section() ) {
			return;
		}

		if ( ! wp_script_is( self::ADMIN_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		if ( ! class_exists( Package::class ) ) {
			return;
		}

		try {
			$container = Package::container();

			/**
			 * The Blocks payment method registry.
			 *
			 * @var PaymentMethodRegistry $payment_method_registry
			 */
			$payment_method_registry = $container->get( PaymentMethodRegistry::class );

			// Note: the integrations register their scripts as a side effect of this call.
			$handles = $payment_method_registry->get_all_active_payment_method_script_dependencies();
			$handles = array_values( array_filter( $handles, 'is_string' ) );

			if ( empty( $handles ) ) {
				return;
			}

			foreach ( $handles as $handle ) {
				wp_enqueue_script( $handle );
			}

			// The admin bundle is enqueued before us, so without this it would print first and
			// read an empty registry. Declaring the payment method scripts as its dependencies
			// forces them to execute earlier.
			$this->add_script_dependencies( self::ADMIN_SCRIPT_HANDLE, $handles );

			// Payment method scripts read their title/description from `wcSettings.paymentMethodData`,
			// which is normally published by the Cart/Checkout blocks. Those never render here, so
			// publish it directly.
			$container->get( BlocksPaymentsApi::class )->add_payment_method_script_data();

			// Discovery above is generic. This is the one piece of gateway-specific knowledge, and
			// it answers a question the registry cannot: whether a provider's methods are its own
			// to render. See StripeOptimizedCheckoutAdapter.
			$asset_registry = $container->get( AssetDataRegistry::class );
			if ( ! $asset_registry->exists( self::ASSET_DATA_KEY ) ) {
				$asset_registry->add(
					self::ASSET_DATA_KEY,
					array( 'groupedProviders' => ( new StripeOptimizedCheckoutAdapter() )->get_grouped_providers() )
				);
			}
		} catch ( Throwable $e ) {
			// A third-party integration can throw while resolving its script handles. Never let
			// that take down the settings page.
			wc_get_logger()->error(
				'Could not load the Checkout Block payment method scripts: ' . $e->getMessage(),
				array( 'source' => 'settings-payments' )
			);
		}
	}

	/**
	 * Check whether the current request is the Payment methods settings section.
	 *
	 * The `$current_section` global is not populated this early in the request, so the query
	 * arguments are read directly.
	 *
	 * @return bool
	 */
	private function is_payment_methods_section(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only used to determine which admin screen is being rendered.
		$page    = isset( $_GET['page'] ) ? wc_clean( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? wc_clean( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? wc_clean( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page
			&& WC_Settings_Payment_Gateways::TAB_NAME === $tab
			&& WC_Settings_Payment_Gateways::PAYMENT_METHODS_SECTION_NAME === $section;
	}

	/**
	 * Append dependencies to an already registered script.
	 *
	 * @param string   $handle       The handle to add the dependencies to.
	 * @param string[] $dependencies The handles to add as dependencies.
	 */
	private function add_script_dependencies( string $handle, array $dependencies ): void {
		$script = wp_scripts()->query( $handle, 'registered' );

		if ( ! $script instanceof _WP_Dependency ) {
			return;
		}

		$script->deps = array_values( array_unique( array_merge( $script->deps, $dependencies ) ) );
	}
}
