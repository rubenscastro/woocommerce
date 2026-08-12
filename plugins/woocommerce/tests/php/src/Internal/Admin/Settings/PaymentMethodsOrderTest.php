<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodsOrder;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use InvalidArgumentException;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentMethodsOrder class.
 */
class PaymentMethodsOrderTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentMethodsOrder
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new PaymentMethodsOrder();
		delete_option( PaymentMethodsOrder::OPTION_NAME );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( PaymentMethodsOrder::OPTION_NAME );
		delete_option( PaymentsProviders::PROVIDERS_ORDER_OPTION );
		remove_all_filters( 'woocommerce_payment_gateways' );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		parent::tearDown();
	}

	/**
	 * @testdox Should weave stored-but-unsubmitted IDs back in after their previous neighbour.
	 * @dataProvider provider_merge_scenarios
	 *
	 * @param string[] $submitted The submitted order.
	 * @param string[] $old       The previously stored order.
	 * @param string[] $expected  The expected merged order.
	 */
	public function test_merge_weaves_retained_ids_after_previous_neighbour( array $submitted, array $old, array $expected ): void {
		$this->assertSame(
			$expected,
			PaymentMethodsOrder::merge( $submitted, $old ),
			'The merge should keep retained IDs anchored to their previous still-present neighbour.'
		);
	}

	/**
	 * Data provider for the merge scenarios.
	 *
	 * @return array<string, array<int, string[]>>
	 */
	public function provider_merge_scenarios(): array {
		return array(
			'reorder with a hidden disabled method'   => array(
				array( 'c', 'a', 'd' ),
				array( 'a', 'b', 'c', 'd' ),
				array( 'c', 'a', 'b', 'd' ),
			),
			'new method placed at the top is honored' => array(
				array( 'm', 'a', 'b', 'c' ),
				array( 'a', 'b', 'c' ),
				array( 'm', 'a', 'b', 'c' ),
			),
			'stale id retained after its anchor'      => array(
				array( 'c', 'a' ),
				array( 'a', 'b', 'c' ),
				array( 'c', 'a', 'b' ),
			),
			'front-anchored retained id keeps front'  => array(
				array( 'b', 'c' ),
				array( 'a', 'b', 'c' ),
				array( 'a', 'b', 'c' ),
			),
			'pure reorder with nothing retained'      => array(
				array( 'c', 'a', 'b' ),
				array( 'a', 'b', 'c' ),
				array( 'c', 'a', 'b' ),
			),
			'multiple retained across a big reorder'  => array(
				array( 'f', 'd', 'a' ),
				array( 'a', 'b', 'c', 'd', 'e', 'f' ),
				array( 'f', 'd', 'e', 'a', 'b', 'c' ),
			),
		);
	}

	/**
	 * @testdox Should report existence based on option presence, not content truthiness.
	 */
	public function test_exists_reflects_option_presence(): void {
		$this->assertFalse( $this->sut->exists(), 'An absent option should not be reported as existing.' );

		update_option( PaymentMethodsOrder::OPTION_NAME, array( 'cod' ) );
		$this->assertTrue( $this->sut->exists(), 'A present option should be reported as existing.' );
	}

	/**
	 * @testdox Should normalize the raw stored order to unique, non-empty string IDs.
	 */
	public function test_get_raw_normalizes_stored_value(): void {
		update_option( PaymentMethodsOrder::OPTION_NAME, array( 'a', 'a', '', 'b' ) );

		$this->assertSame(
			array( 'a', 'b' ),
			$this->sut->get_raw(),
			'Duplicate and empty entries should be removed from the raw order.'
		);
	}

	/**
	 * @testdox Should treat a malformed (non-array) stored option as an empty raw order.
	 */
	public function test_get_raw_returns_empty_for_non_array_option(): void {
		update_option( PaymentMethodsOrder::OPTION_NAME, 'not-an-array' );

		$this->assertTrue( $this->sut->exists(), 'A present option should still be reported as existing.' );
		$this->assertSame( array(), $this->sut->get_raw(), 'A non-array stored value should normalize to an empty raw order.' );
	}

	/**
	 * @testdox Should fall back to the default enabled order when the stored order normalizes to empty.
	 */
	public function test_get_for_checkout_falls_back_when_raw_empty(): void {
		$this->register_fake_gateways(
			array(
				'gw_a' => 'yes',
				'gw_b' => 'yes',
				'gw_c' => 'yes',
			)
		);

		// The option exists but its content is malformed / normalizes to nothing.
		update_option( PaymentMethodsOrder::OPTION_NAME, array( '', '<script></script>' ) );

		$this->assertSame( array(), $this->sut->get_raw(), 'A malformed stored order should normalize to empty.' );
		$this->assertSame(
			array( 'gw_a', 'gw_b', 'gw_c' ),
			$this->sut->get_for_checkout(),
			'With an empty raw order, checkout should fall back to all enabled gateways in default order.'
		);
	}

	/**
	 * @testdox Should persist the submitted order merged with retained stored entries.
	 */
	public function test_save_persists_merged_order(): void {
		update_option( PaymentMethodsOrder::OPTION_NAME, array( 'a', 'b', 'c' ) );

		$result = $this->sut->save( array( 'c', 'a' ) );

		$this->assertTrue( $result, 'A valid save should return true.' );
		$this->assertSame(
			array( 'c', 'a', 'b' ),
			get_option( PaymentMethodsOrder::OPTION_NAME ),
			'The retained "b" should be anchored after "a".'
		);
	}

	/**
	 * @testdox Should de-duplicate a submitted order, keeping the first occurrence.
	 */
	public function test_save_dedupes_submission(): void {
		$this->sut->save( array( 'a', 'b', 'a' ) );

		$this->assertSame(
			array( 'a', 'b' ),
			get_option( PaymentMethodsOrder::OPTION_NAME ),
			'Duplicate submitted IDs should be collapsed to the first occurrence.'
		);
	}

	/**
	 * @testdox Should reject an empty submitted order.
	 */
	public function test_save_rejects_empty_order(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->sut->save( array( '' ) );
	}

	/**
	 * @testdox Should filter the checkout order to enabled gateways and append new enabled ones.
	 */
	public function test_get_for_checkout_filters_enabled_and_appends(): void {
		$this->register_fake_gateways(
			array(
				'gw_a' => 'yes',
				'gw_b' => 'no',
				'gw_c' => 'yes',
				'gw_d' => 'yes',
			)
		);

		update_option( PaymentMethodsOrder::OPTION_NAME, array( 'gw_c', 'gw_a', 'gw_b' ) );

		$this->assertSame(
			array( 'gw_c', 'gw_a', 'gw_d' ),
			$this->sut->get_for_checkout(),
			'Disabled gw_b is excluded; new enabled gw_d is appended in gateway order.'
		);
	}

	/**
	 * @testdox Should build a sequential gateway order map limited to registered gateways.
	 */
	public function test_build_gateway_order_map_filters_registered(): void {
		$this->register_fake_gateways(
			array(
				'gw_a' => 'yes',
				'gw_b' => 'no',
				'gw_c' => 'yes',
			)
		);

		$this->assertSame(
			array(
				'gw_c' => 0,
				'gw_a' => 1,
			),
			$this->sut->build_gateway_order_map( array( 'gw_c', 'gw_a', 'stale_id' ) ),
			'Stale IDs are dropped and registered IDs get sequential positions.'
		);
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
