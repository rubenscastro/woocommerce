<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Api;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodsOrder;
use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use ReflectionMethod;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * End-to-end lifecycle tests for the canonical payment-method order across save and reset.
 */
class PaymentMethodsOrderLifecycleIntegrationTest extends WC_Unit_Test_Case {

	private const GATEWAY_ORDER_OPTION = PaymentsProviders::PROVIDERS_ORDER_OPTION;

	/**
	 * The payments settings service.
	 *
	 * @var Payments
	 */
	private $payments;

	/**
	 * The Blocks payments API.
	 *
	 * @var Api
	 */
	private $blocks_api;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->register_fake_gateways(
			array(
				'gw_a' => 'yes',
				'gw_b' => 'yes',
				'gw_c' => 'yes',
			)
		);

		delete_option( PaymentMethodsOrder::OPTION_NAME );
		delete_option( self::GATEWAY_ORDER_OPTION );

		$this->payments   = wc_get_container()->get( Payments::class );
		$this->blocks_api = Package::container()->get( Api::class );
		$this->payments->clear_cache();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( PaymentMethodsOrder::OPTION_NAME );
		delete_option( self::GATEWAY_ORDER_OPTION );
		$this->payments->clear_cache();
		remove_all_filters( 'woocommerce_payment_gateways' );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		parent::tearDown();
	}

	/**
	 * @testdox Should save the canonical order and project it to both Checkout Block and Classic checkout.
	 */
	public function test_save_propagates_to_both_checkouts(): void {
		// Default state: registration order.
		$this->assertSame( array( 'gw_a', 'gw_b', 'gw_c' ), $this->classic_gateway_ids(), 'Classic should start in registration order.' );
		$this->assertSame( array( 'gw_a', 'gw_b', 'gw_c' ), $this->blocks_sort_order(), 'Blocks should start in registration order.' );

		// Save a custom order.
		$this->payments->update_payment_method_order( array( 'gw_c', 'gw_a', 'gw_b' ) );

		$this->assertSame(
			array( 'gw_c', 'gw_a', 'gw_b' ),
			get_option( PaymentMethodsOrder::OPTION_NAME ),
			'The canonical option should hold the saved order.'
		);
		$this->assertSame( array( 'gw_c', 'gw_a', 'gw_b' ), $this->blocks_sort_order(), 'Blocks should follow the saved order.' );
		$this->assertSame( array( 'gw_c', 'gw_a', 'gw_b' ), $this->classic_gateway_ids(), 'Classic should follow the projected order.' );
	}

	/**
	 * Get the Classic checkout gateway order (re-initializing gateways to read the current option).
	 *
	 * @return string[] The gateway IDs in Classic order.
	 */
	private function classic_gateway_ids(): array {
		$this->refresh_gateways();

		return array_keys( WC()->payment_gateways()->payment_gateways() );
	}

	/**
	 * Get the sort order the Blocks Api would expose (canonical when present, otherwise the fallback).
	 *
	 * @return string[] The resolved Blocks sort order.
	 */
	private function blocks_sort_order(): array {
		// Simulate a fresh request so the gateway list re-sorts from the current option before the
		// fallback path reads it.
		$this->refresh_gateways();

		$method = new ReflectionMethod( $this->blocks_api, 'get_payment_method_sort_order' );
		$method->setAccessible( true );

		return $method->invoke( $this->blocks_api );
	}

	/**
	 * Re-initialize the gateway registry so it re-sorts from the current gateway order option.
	 */
	private function refresh_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Register a deterministic set of fake payment gateways.
	 *
	 * @param array<string, string> $specs Map of gateway ID to enabled flag ('yes'|'no'), in order.
	 */
	private function register_fake_gateways( array $specs ): void {
		$gateways = array();
		foreach ( $specs as $id => $enabled ) {
			$gateways[] = new class( $id, $enabled ) extends WC_Payment_Gateway {
				/**
				 * Build a fake gateway with a fixed ID and enabled flag.
				 *
				 * @param string $id      The gateway ID.
				 * @param string $enabled The enabled flag ('yes'|'no').
				 */
				public function __construct( string $id, string $enabled ) {
					$this->id      = $id;
					$this->enabled = $enabled;
				}
			};
		}

		add_filter(
			'woocommerce_payment_gateways',
			function () use ( $gateways ) {
				return $gateways;
			}
		);

		// Reset the accumulated list so init() rebuilds from just the fake gateways.
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}
}
