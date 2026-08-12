<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Api as BlocksPaymentsApi;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use ReflectionClass;
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
	 * The handle that defines the `wc.wcBlocksData` global.
	 *
	 * @var string
	 */
	private const BLOCKS_DATA_HANDLE = 'wc-blocks-data-store';

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

			// Some integrations reach for `wc.wcBlocksData` at module scope without declaring the
			// handle that defines it, because the Cart/Checkout blocks always happen to have loaded
			// it first. Here nothing else pulls it in, so the destructuring throws and the script
			// registers nothing — Square's Cash App Pay is one. Add it for everyone rather than
			// waiting for each extension to fix its dependency list.
			if ( wp_script_is( self::BLOCKS_DATA_HANDLE, 'registered' ) ) {
				array_unshift( $handles, self::BLOCKS_DATA_HANDLE );
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

			$asset_registry = $container->get( AssetDataRegistry::class );

			// `PaymentMethodRegistry` overrides the generic script data with its own
			// `paymentMethodData` shape, so the `{name}_data` key `IntegrationRegistry` would have
			// published never appears. Integrations that read that key instead — again Square's
			// Cash App Pay — throw on the missing data. Publishing both shapes costs one array and
			// keeps either convention working.
			foreach ( $payment_method_registry->get_all_active_registered() as $name => $integration ) {
				$key = (string) $name . '_data';

				if ( '' === (string) $name || $asset_registry->exists( $key ) ) {
					continue;
				}

				$data = $integration->get_script_data();

				if ( ! empty( $data ) ) {
					$asset_registry->add( $key, $data );
				}
			}

			// Discovery above is generic. This is the one piece of gateway-specific knowledge, and
			// it answers a question the registry cannot: whether a provider's methods are its own
			// to render. See StripeOptimizedCheckoutAdapter.
			if ( ! $asset_registry->exists( self::ASSET_DATA_KEY ) ) {
				$asset_registry->add(
					self::ASSET_DATA_KEY,
					array_merge(
						array(
							'groupedProviders' => ( new StripeOptimizedCheckoutAdapter() )->get_grouped_providers(),
						),
						$this->get_provider_context( $payment_method_registry )
					)
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
	 * The context the list needs to show each method's provider.
	 *
	 * Two maps, joined on the plugin slug: which plugin owns each registered id, and which logo
	 * each plugin has. Sub-gateways such as `stripe_klarna` never appear in the payments providers
	 * list, so ownership is resolved by reflection on the class instead.
	 *
	 * Both the gateways and the Blocks integrations are walked, because neither covers everything:
	 * a provider can register a Checkout Block payment method with no matching `WC_Payment_Gateway`
	 * at all — PayPal Payments' `ppcp-pwc` ("Pay with Crypto") is one — and those methods would
	 * otherwise render with no provider logo.
	 *
	 * @param PaymentMethodRegistry $payment_method_registry The Blocks payment method registry.
	 *
	 * @return array{gatewayPlugins: array<string, string>, providerIcons: array<string, string>, methodIcons: array<string, string>, providerAssets: array<string, string>}
	 */
	private function get_provider_context( PaymentMethodRegistry $payment_method_registry ): array {
		$gateway_plugins = array();

		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			$gateway_plugins[ (string) $gateway_id ] = $this->get_owning_plugin( $gateway );
		}

		foreach ( $payment_method_registry->get_all_active_registered() as $name => $integration ) {
			if ( '' === (string) $name || isset( $gateway_plugins[ (string) $name ] ) ) {
				continue;
			}

			$gateway_plugins[ (string) $name ] = $this->get_owning_plugin( $integration );
		}

		$provider_icons = array();
		$scheme         = wp_parse_url( site_url(), PHP_URL_SCHEME );
		$scheme         = is_string( $scheme ) ? $scheme : 'https';

		// Read the logos straight off the gateways rather than from the payments providers service.
		// That service assembles suggestions and incentives and runs third-party code along the way,
		// so one misbehaving extension takes the whole map down — a Helcim TypeError was doing
		// exactly that here, leaving every row without a provider badge. The gateway's own `icon`
		// is the same value the service would end up reporting, without the blast radius.
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			$plugin_slug = $gateway_plugins[ (string) $gateway_id ] ?? '';
			$icon        = is_string( $gateway->icon ?? null ) ? trim( $gateway->icon ) : '';

			if ( '' === $plugin_slug || isset( $provider_icons[ $plugin_slug ] ) ) {
				continue;
			}

			// Some gateways put an <img> tag, or a list of them, in this property.
			if ( '' === $icon || ! wc_is_valid_url( $icon ) ) {
				continue;
			}

			// Take the scheme from the site URL rather than from `is_ssl()`. Behind a proxy that
			// terminates TLS — ngrok, most load balancers — `is_ssl()` is false even though the page
			// is served over https, so `WC_HTTPS::force_https_url()` leaves the gateway's stored
			// `http://` icon untouched and the browser blocks it as mixed content.
			$provider_icons[ $plugin_slug ] = set_url_scheme( $icon, $scheme );
		}

		return array(
			'gatewayPlugins' => $gateway_plugins,
			'providerIcons'  => $provider_icons,
			'methodIcons'    => $this->get_bundled_icons( 'square' ),
			'providerAssets' => $this->get_bundled_icons( 'rectangle' ),
		);
	}

	/**
	 * The plugin directory slug that owns a gateway or integration class.
	 *
	 * @param object $instance The gateway or integration to inspect.
	 *
	 * @return string
	 */
	private function get_owning_plugin( object $instance ): string {
		$file = ( new ReflectionClass( $instance ) )->getFileName();

		return preg_match( '#/plugins/([^/]+)/#', (string) $file, $matches ) ? $matches[1] : 'woocommerce';
	}

	/**
	 * The bundled payment logos of one shape, keyed by file name.
	 *
	 * Two shapes are shipped and they are different artwork, not the same drawing reframed: the
	 * square set is 40x40 and fills the logo column beside each method name, the rectangle set is
	 * 38x24 and suits the provider badge on the trailing edge. Matching a name against these keys
	 * costs nothing and adds no assets, so it stands in until providers declare their own logos.
	 *
	 * @param string $shape Either `square` or `rectangle`.
	 *
	 * @return array<string, string> Icon slug => URL.
	 */
	private function get_bundled_icons( string $shape ): array {
		$icons     = array();
		$directory = 'assets/images/payment-logos/' . $shape . '/';
		$files     = glob( WC_ABSPATH . $directory . '*.svg' );

		foreach ( (array) $files as $file ) {
			$slug = basename( (string) $file, '.svg' );

			$icons[ $slug ] = plugins_url( $directory . basename( (string) $file ), WC_PLUGIN_FILE );
		}

		return $icons;
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
