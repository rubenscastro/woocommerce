<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Api as BlocksPaymentsApi;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnit;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnits;
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
	 * The payments providers service, used only to read the canonical provider brand name.
	 *
	 * Optional: resolved lazily by the DI container. When absent the provider label degrades to the
	 * owning plugin's header name, so nothing here hard-depends on the service.
	 *
	 * @var PaymentsProviders|null
	 */
	private ?PaymentsProviders $payment_providers = null;

	/**
	 * Initialize the controller's dependencies.
	 *
	 * @internal
	 *
	 * @param PaymentsProviders $payment_providers The payments providers service.
	 */
	final public function init( PaymentsProviders $payment_providers ): void {
		$this->payment_providers = $payment_providers;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// WCAdminAssets::enqueue_assets() runs at priority 15, so the admin bundle is already
		// registered (and enqueued) by the time this runs.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_payment_method_scripts' ), 20 );
	}

	/**
	 * Publish the spike data for whichever Payments settings screen is being rendered.
	 *
	 * The Payment methods section is the primary consumer: it enqueues the Checkout Block payment
	 * method scripts (so the client registry is populated) and publishes the full payload. The main
	 * Payments providers landing page reuses only the duplicate-detection metadata — the same server
	 * source of truth — to show the duplicate notice and open the same resolution modal, without
	 * enqueuing any third-party scripts on that higher-traffic page.
	 *
	 * Everything here is scoped to those two screens, so no other admin screen and no front-end
	 * request is affected.
	 */
	public function enqueue_payment_method_scripts(): void {
		if ( $this->is_payment_methods_section() ) {
			$this->enqueue_and_publish_for_methods_page();
			return;
		}

		if ( $this->is_main_providers_section() ) {
			$this->publish_duplicates_for_providers_page();
		}
	}

	/**
	 * Enqueue the Checkout Block payment method scripts and publish the full payload on the Payment
	 * methods section.
	 */
	private function enqueue_and_publish_for_methods_page(): void {
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
			$this->publish_spike_data( $asset_registry, $payment_method_registry );
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
	 * Publish the duplicate-detection metadata on the main Payments providers landing page.
	 *
	 * Reuses the same server-side detection and resolvable-candidate model the methods page uses —
	 * one source of truth — but does not enqueue any payment method scripts. The metadata, the
	 * resolvable candidates, and the pre-resolved method icons/labels are all computed server-side,
	 * so the providers page can render the duplicate notice and open the same modal without the
	 * client-side payment-method registry.
	 */
	private function publish_duplicates_for_providers_page(): void {
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
			$asset_registry          = $container->get( AssetDataRegistry::class );

			$this->publish_spike_data( $asset_registry, $payment_method_registry );
		} catch ( Throwable $e ) {
			// Advisory metadata only; never let it take down the providers page.
			wc_get_logger()->error(
				'Could not publish payment method duplicate metadata: ' . $e->getMessage(),
				array( 'source' => 'settings-payments' )
			);
		}
	}

	/**
	 * Assemble and publish the spike payload under {@see self::ASSET_DATA_KEY}.
	 *
	 * Idempotent: does nothing when the key is already present (the methods page publishes it first
	 * when both code paths would run in one request). The grouped-providers representation, the
	 * duplicate metadata, and the resolvable duplicate candidates (with their pre-resolved method
	 * icon/label and provider brand) are all derived here so both consuming pages read identical data.
	 *
	 * @param AssetDataRegistry     $asset_registry          The Blocks asset data registry.
	 * @param PaymentMethodRegistry $payment_method_registry The Blocks payment method registry.
	 */
	private function publish_spike_data( AssetDataRegistry $asset_registry, PaymentMethodRegistry $payment_method_registry ): void {
		if ( $asset_registry->exists( self::ASSET_DATA_KEY ) ) {
			return;
		}

		$grouped_providers = ( new StripeOptimizedCheckoutAdapter() )->get_grouped_providers();
		// Duplicate detection is a separate metadata layer: it annotates the methods discovered
		// above, and never adds, removes or reveals a row.
		$duplicates = ( new PaymentMethodDuplicatesDetector() )->detect();
		$context    = $this->get_provider_context( $payment_method_registry );

		$duplicate_providers = $this->get_duplicate_providers(
			$duplicates['payment_methods'],
			$grouped_providers,
			$context['gatewayPlugins'],
			$context['providerIcons'],
			$context['methodIcons']
		);

		$control_units = ( new ExpressControlUnits() )->get_units();

		$express_duplicates = $this->get_express_duplicate_groups(
			$duplicates['express'],
			$control_units,
			$context['gatewayPlugins'],
			$context['providerIcons'],
			$context['methodIcons']
		);

		$asset_registry->add(
			self::ASSET_DATA_KEY,
			array_merge(
				array(
					'groupedProviders'    => $grouped_providers,
					'duplicates'          => $duplicates,
					// The provider options the resolution modal offers per resolvable duplicate.
					// Only regular methods the representation surfaces individually appear here.
					'duplicateProviders'  => $duplicate_providers,
					// The same, for express wallets: one entry per duplicated wallet, whose options
					// are control units rather than gateways.
					'expressDuplicates'   => $express_duplicates,
					// The full unit graph, so the modal can preview what a choice would disable.
					'expressControlUnits' => $this->serialize_control_units( $control_units ),
					// Labels for every wallet in the graph, including ones that are not themselves
					// duplicated — a consequence often names exactly such a wallet.
					'expressWalletLabels' => $this->get_express_wallet_labels( $duplicates['express'], $control_units ),
				),
				$context
			)
		);

		// The Blocks asset-data registry only prints its published data when the `wc-settings` script is
		// enqueued. The methods page pulls that in through the payment-method scripts it enqueues; the
		// providers page enqueues none, so ensure it here when there is a duplicate notice to surface —
		// otherwise the data would be stored but never reach the client. No-op if already enqueued.
		if ( ( ! empty( $duplicate_providers ) || ! empty( $express_duplicates ) ) && wp_script_is( 'wc-settings', 'registered' ) ) {
			wp_enqueue_script( 'wc-settings' );
		}
	}

	/**
	 * The decisions the resolution modal offers for duplicated express methods.
	 *
	 * The merchant makes **one choice per decision group**, and an option is a **provider** — not a
	 * gateway, and not a control unit. Both of those follow from how wallets are really controlled:
	 *
	 * - A provider can offer a wallet through several gateway ids, or through several control units
	 *   (PayPal runs Apple Pay and Google Pay as two independent units). Those are one choice to the
	 *   merchant — "keep PayPal" — so an option unions everything that provider contributes.
	 * - A provider whose single setting controls several wallets ties those wallets together. Asking
	 *   separately for each would let the merchant express combinations that cannot exist, so the
	 *   wallets it binds are decided together, in one group.
	 *
	 * Groups are therefore the connected components of "wallets linked by a shared control unit",
	 * which also means every wallet a participating unit touches is inside the group it belongs to —
	 * so a choice can never disable a method belonging to some other decision.
	 *
	 * An option carries the subset of the group's methods it actually provides. Where that is less
	 * than the whole group, the difference is exactly what the modal warns will be disabled.
	 *
	 * @param array<string, string[]> $express_groups  The detector's `express` output.
	 * @param ExpressControlUnit[]    $control_units   The registered control units.
	 * @param array<string, string>   $gateway_plugins Gateway id => owning plugin slug.
	 * @param array<string, string>   $provider_icons  Plugin slug => provider logo URL.
	 * @param array<string, string>   $method_icons    Bundled method icon slug => URL (square set).
	 *
	 * @return array<int, array{id: string, walletIds: string[], label: string, icons: string[], options: array<int, array{providerSlug: string, providerLabel: string, providerIcon: string, controlUnitIds: string[], covers: string[], supportsDisabled: string[], canDisable: bool}>}>
	 */
	private function get_express_duplicate_groups( array $express_groups, array $control_units, array $gateway_plugins, array $provider_icons, array $method_icons = array() ): array {
		try {
			if ( empty( $express_groups ) ) {
				return array();
			}

			// Everything that participates in a duplicate, keyed by wallet, as a provider-agnostic
			// "who offers this" map. Gateways no unit claims stand in for themselves, so a provider
			// without an adapter is still offered rather than silently dropped.
			$offers = array();

			foreach ( $express_groups as $wallet_id => $gateway_ids ) {
				$wallet_id = (string) $wallet_id;

				foreach ( (array) $gateway_ids as $gateway_id ) {
					$gateway_id = (string) $gateway_id;
					$unit       = $this->find_unit_for_gateway( $gateway_id, $control_units );
					$key        = null === $unit ? $gateway_id : $unit->get_id();

					$offers[ $wallet_id ][ $key ] = array(
						'unit'        => $unit,
						'gateway_id'  => $gateway_id,
						'plugin_slug' => null !== $unit && '' !== $unit->get_provider_slug()
							? $unit->get_provider_slug()
							: ( $gateway_plugins[ $gateway_id ] ?? '' ),
					);
				}
			}

			$plugin_names = $this->get_plugin_names();
			$result       = array();

			foreach ( $this->group_express_wallets( array_keys( $offers ), $offers ) as $duplicated_ids ) {
				// Everything the participating providers offer, keyed by provider.
				$options    = array();
				$resolvable = true;

				foreach ( $duplicated_ids as $wallet_id ) {
					foreach ( $offers[ $wallet_id ] ?? array() as $key => $offer ) {
						// Resolving means turning every provider but one off, and only a control
						// unit can be turned off. A participant with no unit could therefore be
						// kept but never dropped, so the decision is only half answerable — and
						// offering half a decision means some choices fail after the merchant
						// commits. Drop the whole decision instead of asking a question that
						// cannot be honoured either way.
						if ( null === $offer['unit'] ) {
							$resolvable = false;
						}

						// Options are keyed by provider, so several units of one provider — and the
						// several methods they cover — read as the single choice they are.
						$provider_key = '' !== $offer['plugin_slug'] ? $offer['plugin_slug'] : $key;

						if ( ! isset( $options[ $provider_key ] ) ) {
							$options[ $provider_key ] = array(
								'providerSlug'     => $offer['plugin_slug'],
								'providerLabel'    => $this->get_provider_brand_label( $offer['plugin_slug'], $plugin_names ),
								'providerIcon'     => '' !== $offer['plugin_slug'] ? ( $provider_icons[ $offer['plugin_slug'] ] ?? '' ) : '',
								'controlUnitIds'   => array(),
								'covers'           => array(),
								'supportsDisabled' => array(),
								'canDisable'       => true,
							);
						}

						$options[ $provider_key ]['controlUnitIds'][] = $key;

						// A unit's whole reach counts, not just the method that surfaced it. That is
						// what pulls a method which is *not* itself duplicated into the decision —
						// the Google Pay in "choosing PayPal for Apple Pay will also disable Google
						// Pay" is exactly such a method.
						$options[ $provider_key ]['covers'] = array_merge(
							$options[ $provider_key ]['covers'],
							null === $offer['unit'] ? array( $wallet_id ) : $offer['unit']->get_wallets()
						);

						if ( null !== $offer['unit'] && ! $offer['unit']->can_disable() ) {
							$options[ $provider_key ]['canDisable'] = false;
						}
					}
				}

				// The decision covers every method the participating providers touch, so nothing can
				// be turned off without having been shown here.
				$wallet_ids = $duplicated_ids;

				foreach ( $options as $provider_key => $option ) {
					$options[ $provider_key ]['controlUnitIds'] = array_values( array_unique( $option['controlUnitIds'] ) );
					$options[ $provider_key ]['covers']         = array_values( array_unique( $option['covers'] ) );

					$wallet_ids = array_merge( $wallet_ids, $options[ $provider_key ]['covers'] );
				}

				$wallet_ids = array_values( array_unique( $wallet_ids ) );

				// An option only "covers" what is actually part of this decision.
				foreach ( $options as $provider_key => $option ) {
					$options[ $provider_key ]['covers'] = array_values(
						array_intersect( $option['covers'], $wallet_ids )
					);

					// What an option does not cover is not automatically what its provider cannot
					// offer. Both cases end with the method turned off, but only one of them is the
					// merchant's to undo, so they are recorded apart.
					$options[ $provider_key ]['supportsDisabled'] = $this->get_disabled_support(
						$option['providerSlug'],
						array_diff( $wallet_ids, $options[ $provider_key ]['covers'] ),
						$control_units
					);
				}

				$labels = array();
				$icons  = array();

				foreach ( $wallet_ids as $wallet_id ) {
					$labels[] = $this->get_canonical_method_label( $wallet_id );
					$icon     = $this->get_canonical_method_icon( $wallet_id, $method_icons );

					if ( '' !== $icon ) {
						$icons[] = $icon;
					}
				}

				if ( ! $resolvable ) {
					continue;
				}

				$result[] = array(
					'id'        => implode( '+', $wallet_ids ),
					'walletIds' => $wallet_ids,
					'label'     => $this->join_method_labels( $labels ),
					'icons'     => $icons,
					'options'   => array_values( $options ),
				);
			}

			return $result;
		} catch ( Throwable $e ) {
			// Express metadata is advisory; never let it break the settings page.
			return array();
		}
	}

	/**
	 * The methods a provider offers but currently has switched off.
	 *
	 * A method the chosen provider does not carry gets turned off either way, but the reason is not
	 * the same thing to the merchant. "This provider does not offer it" is final. "This provider
	 * offers it and it is switched off" is a state they can change in that provider's own settings,
	 * and then the method survives the resolution. Reporting the second as the first says something
	 * untrue about the provider and hides the one action that keeps the method.
	 *
	 * Only registered control units count. A provider that supports a method somewhere in its
	 * product but exposes no unit for it is, from here, indistinguishable from one that does not
	 * support it at all, so it is left out rather than guessed at.
	 *
	 * @param string               $provider_slug The provider's plugin slug.
	 * @param string[]             $wallet_ids    The methods the provider does not currently carry.
	 * @param ExpressControlUnit[] $control_units The registered control units.
	 *
	 * @return string[] The subset of $wallet_ids the provider has a switched-off unit for.
	 */
	private function get_disabled_support( string $provider_slug, array $wallet_ids, array $control_units ): array {
		if ( '' === $provider_slug || empty( $wallet_ids ) ) {
			return array();
		}

		$supported = array();

		foreach ( $control_units as $unit ) {
			if ( ! $unit instanceof ExpressControlUnit || $unit->is_enabled() || $provider_slug !== $unit->get_provider_slug() ) {
				continue;
			}

			foreach ( $wallet_ids as $wallet_id ) {
				if ( $unit->provides_wallet( (string) $wallet_id ) ) {
					$supported[ (string) $wallet_id ] = true;
				}
			}
		}

		return array_keys( $supported );
	}

	/**
	 * Group wallets that must be decided together.
	 *
	 * Two wallets belong to the same decision when some participating control unit provides both:
	 * that unit cannot be kept for one and dropped for the other, so offering them separately would
	 * invite a combination that cannot exist. Grouping is transitive, so a chain of overlapping units
	 * collapses into a single decision.
	 *
	 * @param string[]                                   $wallet_ids The duplicated wallet ids.
	 * @param array<string, array<string, array<mixed>>> $offers     Participating offers keyed by wallet then unit.
	 *
	 * @return array<int, string[]> Groups of wallet ids.
	 */
	private function group_express_wallets( array $wallet_ids, array $offers ): array {
		$groups = array();

		foreach ( $wallet_ids as $wallet_id ) {
			$merged_into = null;

			foreach ( $groups as $index => $group ) {
				foreach ( $group as $member ) {
					// A shared unit between the two wallets binds them into one decision.
					if ( array_intersect_key( $offers[ $wallet_id ], $offers[ $member ] ) ) {
						$merged_into = $index;
						break 2;
					}
				}
			}

			if ( null === $merged_into ) {
				$groups[] = array( $wallet_id );
				continue;
			}

			$groups[ $merged_into ][] = $wallet_id;
		}

		return $groups;
	}

	/**
	 * Join method labels into a readable list.
	 *
	 * @param string[] $labels The method labels.
	 *
	 * @return string
	 */
	private function join_method_labels( array $labels ): string {
		if ( count( $labels ) < 2 ) {
			return (string) ( $labels[0] ?? '' );
		}

		return sprintf(
			/* translators: 1: all express method names except the last, comma separated. 2: the last express method name. */
			esc_html__( '%1$s and %2$s', 'woocommerce' ),
			implode( ', ', array_slice( $labels, 0, -1 ) ),
			end( $labels )
		);
	}

	/**
	 * The control unit that claims a gateway id, if any.
	 *
	 * @param string               $gateway_id    The gateway id.
	 * @param ExpressControlUnit[] $control_units The registered control units.
	 *
	 * @return ExpressControlUnit|null
	 */
	private function find_unit_for_gateway( string $gateway_id, array $control_units ): ?ExpressControlUnit {
		foreach ( $control_units as $unit ) {
			if ( in_array( $gateway_id, $unit->get_gateway_ids(), true ) ) {
				return $unit;
			}
		}

		return null;
	}

	/**
	 * Readable labels for every wallet the modal may need to name.
	 *
	 * Wider than the duplicated wallets on purpose. The most important sentence the modal shows —
	 * "Google Pay will be disabled" — names a wallet that is typically *not* duplicated, and so has
	 * no row and no candidate of its own. Labelling only duplicated wallets would leave the merchant
	 * reading a raw id at exactly the moment the message matters most.
	 *
	 * @param array<string, string[]> $express_groups The detector's `express` output.
	 * @param ExpressControlUnit[]    $control_units  The registered control units.
	 *
	 * @return array<string, string> Wallet id => label.
	 */
	private function get_express_wallet_labels( array $express_groups, array $control_units ): array {
		$wallet_ids = array_keys( $express_groups );

		foreach ( $control_units as $unit ) {
			foreach ( $unit->get_wallets() as $wallet_id ) {
				$wallet_ids[] = $wallet_id;
			}
		}

		$labels = array();

		foreach ( array_unique( $wallet_ids ) as $wallet_id ) {
			$wallet_id            = (string) $wallet_id;
			$labels[ $wallet_id ] = $this->get_canonical_method_label( $wallet_id );
		}

		return $labels;
	}

	/**
	 * Flatten control units for the client payload.
	 *
	 * @param ExpressControlUnit[] $control_units The registered control units.
	 *
	 * @return array<int, array{id: string, providerSlug: string, providerLabel: string, gatewayIds: string[], wallets: string[], enabled: bool, canDisable: bool, requires: string[]}>
	 */
	private function serialize_control_units( array $control_units ): array {
		$serialized = array();

		foreach ( $control_units as $unit ) {
			$serialized[] = array(
				'id'            => $unit->get_id(),
				'providerSlug'  => $unit->get_provider_slug(),
				'providerLabel' => $unit->get_provider_label(),
				'gatewayIds'    => $unit->get_gateway_ids(),
				'wallets'       => $unit->get_wallets(),
				'enabled'       => $unit->is_enabled(),
				'canDisable'    => $unit->can_disable(),
				'requires'      => $unit->get_requires(),
			);
		}

		return $serialized;
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
	 * Check whether the current request is the main Payments providers landing page.
	 *
	 * That page is the `checkout` tab with either no section or the explicit `main` section — the same
	 * screen `WC_Settings_Payment_Gateways` treats as its default. The other named sections (offline,
	 * the individual offline methods, and the payment-methods reorder screen) are excluded.
	 *
	 * @return bool
	 */
	private function is_main_providers_section(): bool {
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
			&& ( '' === $section || WC_Settings_Payment_Gateways::MAIN_SECTION_NAME === $section );
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
	 * The provider options the resolution modal offers for each resolvable regular duplicate.
	 *
	 * Reuses the representation authority ({@see DuplicateResolutionCandidates} over the grouped
	 * providers) so only implementations surfaced as individual regular methods are offered — Stripe's
	 * Optimized-Checkout children never appear here.
	 *
	 * Each canonical carries a pre-resolved `methodLabel` and `methodIcon` describing the payment
	 * *method* (the row's identity — e.g. the generic Card icon for `card`, never a provider logo) so
	 * the modal can render without the client-side payment-method registry, which the providers page
	 * does not load. Each implementation carries the *provider* brand: `providerLabel` reuses the same
	 * canonical brand the providers list shows (the matched payment-extension suggestion title, e.g.
	 * "Stripe"), falling back to the owning plugin's header name when no suggestion matches.
	 *
	 * @param array<string, string[]>                                                                                           $regular_groups   The detector's `payment_methods` output.
	 * @param array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}> $grouped_providers The grouped-providers representation.
	 * @param array<string, string>                                                                                             $gateway_plugins  Gateway id => owning plugin slug.
	 * @param array<string, string>                                                                                             $provider_icons   Plugin slug => provider logo URL.
	 * @param array<string, string>                                                                                             $method_icons     Bundled method icon slug => URL (square set).
	 *
	 * @return array<string, array{methodLabel: string, methodIcon: string, implementations: array<int, array{gatewayId: string, providerSlug: string, providerLabel: string, providerIcon: string, canDisable: bool}>, requiredKeepGatewayId: string|null}>
	 */
	private function get_duplicate_providers( array $regular_groups, array $grouped_providers, array $gateway_plugins, array $provider_icons, array $method_icons = array() ): array {
		try {
			$resolvable = ( new DuplicateResolutionCandidates() )->compute( $regular_groups, $grouped_providers )['resolvable'];

			// The resolver owns the disable-capability model (which implementations can be disabled and
			// whether a canonical has a single required keep), so the payload matches what the resolver
			// will validate at apply time. Canonicals with more than one non-disableable implementation
			// are omitted here — they are not safely resolvable through the modal.
			$candidates = ( new PaymentMethodDuplicatesResolver() )->describe_candidates( $resolvable, $grouped_providers );

			if ( empty( $candidates ) ) {
				return array();
			}

			$plugin_names = $this->get_plugin_names();

			$duplicate_providers = array();

			foreach ( $candidates as $canonical_id => $candidate ) {
				$canonical_id    = (string) $canonical_id;
				$implementations = array();

				foreach ( $candidate['implementations'] as $implementation ) {
					$gateway_id  = $implementation['gatewayId'];
					$plugin_slug = $gateway_plugins[ $gateway_id ] ?? '';

					$implementations[] = array(
						'gatewayId'     => $gateway_id,
						'providerSlug'  => $plugin_slug,
						'providerLabel' => $this->get_provider_brand_label( $plugin_slug, $plugin_names ),
						'providerIcon'  => '' !== $plugin_slug ? ( $provider_icons[ $plugin_slug ] ?? '' ) : '',
						'canDisable'    => $implementation['canDisable'],
					);
				}

				$duplicate_providers[ $canonical_id ] = array(
					'methodLabel'           => $this->get_canonical_method_label( $canonical_id ),
					'methodIcon'            => $this->get_canonical_method_icon( $canonical_id, $method_icons ),
					'implementations'       => $implementations,
					'requiredKeepGatewayId' => $candidate['requiredKeepGatewayId'],
				);
			}

			return $duplicate_providers;
		} catch ( Throwable $e ) {
			// Provider options are advisory; a misbehaving gateway must not break discovery.
			return array();
		}
	}

	/**
	 * A readable method name for a canonical duplicate id.
	 *
	 * The canonical id is the method's stable identity from the detector (`card`, `klarna`, `ideal`…),
	 * so a humanised form of it names the *method* independently of any provider. This is a method
	 * label, not a provider brand, so plain humanisation is appropriate here (unlike provider names,
	 * which come from canonical provider metadata).
	 *
	 * @param string $canonical_id The canonical method id.
	 *
	 * @return string
	 */
	private function get_canonical_method_label( string $canonical_id ): string {
		$label = ucwords( str_replace( array( '-', '_' ), ' ', $canonical_id ) );

		return '' !== $label ? $label : $canonical_id;
	}

	/**
	 * The bundled icon that represents a canonical payment *method*, never a provider logo.
	 *
	 * Follows payment-method identity: the icon is chosen from the canonical method id against the same
	 * bundled square set the methods page uses. Card resolves to the generic payment-card icon
	 * (`generic`, which is a card artwork), so Stripe's Card is shown with a Card icon rather than the
	 * Stripe provider logo; other canonicals match their like-named bundled icon (klarna, ideal, …) and
	 * fall back to the generic card icon when there is no closer match.
	 *
	 * @param string                $canonical_id The canonical method id.
	 * @param array<string, string> $method_icons Bundled method icon slug => URL (square set).
	 *
	 * @return string The icon URL, or an empty string when the bundled set is unavailable.
	 */
	private function get_canonical_method_icon( string $canonical_id, array $method_icons ): string {
		// The generic card artwork stands in for Card and as the ultimate fallback for any method
		// without a like-named bundled icon.
		$generic = $method_icons['generic'] ?? '';

		if ( 'card' === $canonical_id ) {
			return $generic;
		}

		$slug = str_replace( array( '-', '_' ), '', $canonical_id );

		return $method_icons[ $canonical_id ] ?? ( $method_icons[ $slug ] ?? $generic );
	}

	/**
	 * The merchant-facing provider brand for a plugin slug.
	 *
	 * Reuses the same canonical brand the Payments providers list shows: the matched payment-extension
	 * suggestion's title (e.g. the Stripe gateway plugin resolves to "Stripe", not the plugin header
	 * "WooCommerce Stripe Gateway"). See {@see PaymentsProviders::enhance_payment_gateway_details()},
	 * which hoists that same suggestion title onto each provider row. Falls back to the owning plugin's
	 * header name when there is no matching suggestion (or the providers service is unavailable), so an
	 * unknown provider still gets a readable label.
	 *
	 * @param string                $plugin_slug  The owning plugin directory slug.
	 * @param array<string, string> $plugin_names Plugin directory slug => plugin header name.
	 *
	 * @return string
	 */
	private function get_provider_brand_label( string $plugin_slug, array $plugin_names ): string {
		if ( '' === $plugin_slug ) {
			return '';
		}

		if ( null !== $this->payment_providers ) {
			try {
				// The suggestions are keyed by the normalized (official) plugin slug, so normalize the
				// gateway's directory slug the same way the providers service does before matching.
				$suggestion = $this->payment_providers->get_extension_suggestion_by_plugin_slug( Utils::normalize_plugin_slug( $plugin_slug ) );

				if ( is_array( $suggestion ) && ! empty( $suggestion['title'] ) ) {
					// WooPayments' suggestion title is marketing copy ("Accept payments with Woo"); use the
					// brand name instead. Hardcoded for the spike rather than adding a provider-brand seam.
					if ( isset( $suggestion['id'] ) && 'woopayments' === $suggestion['id'] ) {
						return 'WooPayments';
					}

					return (string) $suggestion['title'];
				}
			} catch ( Throwable $e ) {
				// Fall through to the plugin-header name below.
				$suggestion = null;
			}
		}

		return $this->get_provider_label( $plugin_slug, $plugin_names );
	}

	/**
	 * The installed plugins' display names, keyed by their directory slug.
	 *
	 * This is the owning-plugin identity — the authoritative provider name — read from the plugin
	 * header, deliberately independent of any gateway or method title.
	 *
	 * @return array<string, string> Plugin directory slug => plugin name.
	 */
	private function get_plugin_names(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$names = array();

		foreach ( get_plugins() as $plugin_file => $data ) {
			$slug = (string) strtok( (string) $plugin_file, '/' );

			if ( '' === $slug || isset( $names[ $slug ] ) ) {
				continue;
			}

			$name = isset( $data['Name'] ) ? trim( wp_strip_all_tags( (string) $data['Name'] ) ) : '';

			if ( '' !== $name ) {
				$names[ $slug ] = $name;
			}
		}

		return $names;
	}

	/**
	 * The display label for a provider: its owning plugin's name.
	 *
	 * @param string                $plugin_slug  The owning plugin slug.
	 * @param array<string, string> $plugin_names Plugin directory slug => plugin name.
	 *
	 * @return string
	 */
	private function get_provider_label( string $plugin_slug, array $plugin_names ): string {
		if ( '' === $plugin_slug ) {
			return '';
		}

		if ( isset( $plugin_names[ $plugin_slug ] ) ) {
			return $plugin_names[ $plugin_slug ];
		}

		// Last resort when the plugin header cannot be read: a readable form of the slug.
		return ucwords( str_replace( array( '-', '_' ), ' ', $plugin_slug ) );
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
