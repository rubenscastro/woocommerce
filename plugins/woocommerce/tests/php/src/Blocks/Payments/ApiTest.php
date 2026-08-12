<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Blocks\Payments;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Api;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodsOrder;
use ReflectionMethod;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Tests for the Blocks Payments Api paymentMethodSortOrder source.
 */
class ApiTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var Api
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = Package::container()->get( Api::class );
		delete_option( PaymentMethodsOrder::OPTION_NAME );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( PaymentMethodsOrder::OPTION_NAME );
		remove_all_filters( 'woocommerce_payment_gateways' );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		parent::tearDown();
	}

	/**
	 * @testdox Should fall back to the enabled gateway order when no canonical order exists.
	 */
	public function test_falls_back_to_enabled_gateway_order(): void {
		$this->register_fake_gateways(
			array(
				'gw_a' => 'yes',
				'gw_b' => 'no',
				'gw_c' => 'yes',
			)
		);

		$this->assertSame(
			array( 'gw_a', 'gw_c' ),
			$this->invoke_sort_order(),
			'Without a canonical order, the enabled gateway IDs should be returned in gateway order.'
		);
	}

	/**
	 * @testdox Should use the canonical payment-method order when it exists.
	 */
	public function test_uses_canonical_order_when_present(): void {
		$this->register_fake_gateways(
			array(
				'gw_a' => 'yes',
				'gw_c' => 'yes',
			)
		);
		update_option( PaymentMethodsOrder::OPTION_NAME, array( 'gw_c', 'gw_a' ) );

		$this->assertSame(
			array( 'gw_c', 'gw_a' ),
			$this->invoke_sort_order(),
			'The persisted canonical order should be used as the sort order.'
		);
	}

	/**
	 * Invoke the private get_payment_method_sort_order() method.
	 *
	 * @return string[] The resolved sort order.
	 */
	private function invoke_sort_order(): array {
		$method = new ReflectionMethod( $this->sut, 'get_payment_method_sort_order' );
		$method->setAccessible( true );

		return $method->invoke( $this->sut );
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
