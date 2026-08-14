<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodDuplicatesDetector;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentMethodDuplicatesDetector class.
 */
class PaymentMethodDuplicatesDetectorTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentMethodDuplicatesDetector
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new PaymentMethodDuplicatesDetector();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_payment_gateways' );
		remove_all_filters( 'woocommerce_payment_method_duplicate_definitions' );
		remove_all_filters( 'woocommerce_payment_method_duplicate_gateway_hints' );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		parent::tearDown();
	}

	/**
	 * @testdox Should return an empty result when a canonical method has only one implementation.
	 */
	public function test_no_duplicates_returns_empty_result(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways( array( 'foo_klarna' => 'yes' ) );

		$result = $this->sut->detect();

		$this->assertSame( array(), $result['payment_methods'], 'A single implementation is not a duplicate.' );
		$this->assertSame( array(), $result['express'], 'No express duplicates expected.' );
	}

	/**
	 * @testdox Should group two gateways of the same canonical method into one regular duplicate.
	 */
	public function test_single_regular_duplicate_group(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
			)
		);

		$result = $this->sut->detect();

		$this->assertArrayHasKey( 'klarna', $result['payment_methods'], 'The klarna collision should be reported.' );
		$this->assertEqualsCanonicalizing(
			array( 'foo_klarna', 'bar_klarna' ),
			$result['payment_methods']['klarna'],
			'Both klarna gateways should be in the group.'
		);
		$this->assertSame( array(), $result['express'], 'No express duplicates expected.' );
	}

	/**
	 * @testdox Should report each canonical method separately when several are duplicated.
	 */
	public function test_multiple_regular_duplicate_groups(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
				'ideal'  => array(
					'keywords' => array( 'ideal' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
				'a_ideal'    => 'yes',
				'b_ideal'    => 'yes',
			)
		);

		$result = $this->sut->detect();

		$this->assertArrayHasKey( 'klarna', $result['payment_methods'], 'The klarna collision should be reported.' );
		$this->assertArrayHasKey( 'ideal', $result['payment_methods'], 'The ideal collision should be reported.' );
		$this->assertCount( 2, $result['payment_methods']['klarna'], 'Klarna has two implementations.' );
		$this->assertCount( 2, $result['payment_methods']['ideal'], 'iDEAL has two implementations.' );
	}

	/**
	 * @testdox Should ignore disabled gateways when detecting duplicates.
	 */
	public function test_disabled_gateway_is_excluded(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'no',
			)
		);

		$result = $this->sut->detect();

		$this->assertSame( array(), $result['payment_methods'], 'A disabled second implementation should not make a duplicate.' );
	}

	/**
	 * @testdox Should detect a collision that does not involve WooPayments or any specific provider.
	 */
	public function test_detects_duplicate_without_woopayments(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'acme_klarna' => 'yes',
				'zeta_klarna' => 'yes',
			)
		);

		$result = $this->sut->detect();

		$this->assertArrayHasKey( 'klarna', $result['payment_methods'], 'A non-WooPayments collision must still be detected.' );
		$this->assertNotContains( 'woocommerce_payments', $result['payment_methods']['klarna'], 'No WooPayments gateway is involved here.' );
	}

	/**
	 * @testdox Should detect card duplicates from baseline keywords together with a gateway hint.
	 */
	public function test_card_duplicate_from_baseline_and_hint(): void {
		// `woocommerce_payments` provides a card method but its id carries no card keyword, so it is
		// contributed as a hint; `stripe_cc` matches the baseline `cc` keyword.
		$this->contribute_hints( array( 'card' => array( 'woocommerce_payments' ) ) );
		$this->register_fake_gateways(
			array(
				'stripe_cc'            => 'yes',
				'woocommerce_payments' => 'yes',
			)
		);

		$result = $this->sut->detect();

		$this->assertArrayHasKey( 'card', $result['payment_methods'], 'The card collision should be reported.' );
		$this->assertEqualsCanonicalizing(
			array( 'stripe_cc', 'woocommerce_payments' ),
			$result['payment_methods']['card'],
			'Both the keyword-matched and hinted card gateways should be grouped.'
		);
	}

	/**
	 * @testdox Should not trust a gateway hint for a gateway that is not enabled.
	 */
	public function test_gateway_hint_ignored_when_gateway_disabled(): void {
		$this->contribute_hints( array( 'card' => array( 'woocommerce_payments' ) ) );
		$this->register_fake_gateways(
			array(
				'stripe_cc'            => 'yes',
				'woocommerce_payments' => 'no',
			)
		);

		$result = $this->sut->detect();

		$this->assertSame( array(), $result['payment_methods'], 'A disabled hinted gateway must not form a duplicate.' );
	}

	/**
	 * @testdox Should report express-wallet overlap in the combined express bucket.
	 */
	public function test_express_collision_uses_combined_bucket(): void {
		$this->register_fake_gateways(
			array(
				'applepay_button' => 'yes',
				'foo_google_pay'  => 'yes',
			)
		);

		$result = $this->sut->detect();

		$this->assertArrayHasKey( 'apple_pay_google_pay', $result['express'], 'Wallet overlap should land in the combined express bucket.' );
		$this->assertCount( 2, $result['express']['apple_pay_google_pay'], 'Both wallet gateways should be grouped.' );
		$this->assertArrayNotHasKey( 'apple_pay_google_pay', $result['payment_methods'], 'Express duplicates must not appear among regular methods.' );
	}

	/**
	 * @testdox Should not report a single express implementation as a duplicate.
	 */
	public function test_single_express_implementation_is_not_a_duplicate(): void {
		$this->register_fake_gateways( array( 'applepay_button' => 'yes' ) );

		$result = $this->sut->detect();

		$this->assertSame( array(), $result['express'], 'A single wallet implementation is not a duplicate.' );
	}

	/**
	 * Contribute canonical definitions to the detector, preserving the core baseline.
	 *
	 * @param array<string, array{keywords: string[], express: bool}> $definitions Definitions to add.
	 */
	private function contribute_definitions( array $definitions ): void {
		add_filter(
			'woocommerce_payment_method_duplicate_definitions',
			static function ( $baseline ) use ( $definitions ) {
				return array_merge( (array) $baseline, $definitions );
			}
		);
	}

	/**
	 * Contribute probe-based gateway hints to the detector.
	 *
	 * @param array<string, string[]> $hints Gateway ids keyed by canonical method id.
	 */
	private function contribute_hints( array $hints ): void {
		add_filter(
			'woocommerce_payment_method_duplicate_gateway_hints',
			static function ( $existing ) use ( $hints ) {
				return array_merge( (array) $existing, $hints );
			}
		);
	}

	/**
	 * Register a deterministic set of fake payment gateways.
	 *
	 * @param array<string, string> $specs Map of gateway id to enabled flag ('yes'|'no'), in order.
	 */
	private function register_fake_gateways( array $specs ): void {
		$gateways = array();
		foreach ( $specs as $id => $enabled ) {
			$gateways[] = new class( $id, $enabled ) extends WC_Payment_Gateway {
				/**
				 * Build a fake gateway with a fixed id and enabled flag.
				 *
				 * @param string $id      The gateway id.
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
			static function () use ( $gateways ) {
				return $gateways;
			}
		);

		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}
}
