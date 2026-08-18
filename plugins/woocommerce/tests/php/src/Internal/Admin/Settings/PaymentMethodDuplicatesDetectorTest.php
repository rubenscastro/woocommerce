<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnit;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnitProviderInterface;
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
		remove_all_filters( 'woocommerce_express_checkout_control_unit_providers' );
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
	 * @testdox Should report each wallet as its own canonical express method.
	 */
	public function test_express_collision_is_reported_per_wallet(): void {
		$this->register_fake_gateways(
			array(
				'applepay_button' => 'yes',
				'foo_applepay'    => 'yes',
				'a_google_pay'    => 'yes',
				'b_google_pay'    => 'yes',
			)
		);

		$result = $this->sut->detect();

		$this->assertArrayHasKey( 'apple_pay', $result['express'], 'The Apple Pay collision should be reported on its own.' );
		$this->assertArrayHasKey( 'google_pay', $result['express'], 'The Google Pay collision should be reported on its own.' );
		$this->assertEqualsCanonicalizing(
			array( 'applepay_button', 'foo_applepay' ),
			$result['express']['apple_pay'],
			'Only the Apple Pay gateways belong to the Apple Pay group.'
		);
		$this->assertEqualsCanonicalizing(
			array( 'a_google_pay', 'b_google_pay' ),
			$result['express']['google_pay'],
			'Only the Google Pay gateways belong to the Google Pay group.'
		);
		$this->assertArrayNotHasKey( 'apple_pay', $result['payment_methods'], 'Express duplicates must not appear among regular methods.' );
	}

	/**
	 * @testdox Should not merge two different wallets into one duplicate group.
	 */
	public function test_two_different_wallets_are_not_a_duplicate_of_each_other(): void {
		$this->register_fake_gateways(
			array(
				'foo_applepay'   => 'yes',
				'bar_google_pay' => 'yes',
			)
		);

		$result = $this->sut->detect();

		$this->assertSame(
			array(),
			$result['express'],
			'One Apple Pay and one Google Pay implementation are two single wallets, not a collision.'
		);
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
	 * @testdox Should not report one provider as a duplicate of itself when it surfaces a wallet through several gateways.
	 */
	public function test_one_provider_with_several_wallet_gateways_is_not_a_duplicate(): void {
		$this->register_fake_gateways(
			array(
				'acme_applepay' => 'yes',
				'acme_wallets'  => 'yes',
			)
		);
		// The provider also surfaces the wallet through its master gateway, as a hint would report.
		$this->contribute_hints( array( 'apple_pay' => array( 'acme_wallets' ) ) );
		$this->contribute_control_units(
			array(
				new ExpressControlUnit(
					'acme:wallets',
					'acme',
					'Acme',
					array( 'acme_applepay', 'acme_wallets' ),
					array( ExpressControlUnit::WALLET_APPLE_PAY ),
					true
				),
			)
		);

		$result = $this->sut->detect();

		$this->assertSame(
			array(),
			$result['express'],
			'Two gateway ids owned by the same control unit are one implementation, not a duplicate.'
		);
	}

	/**
	 * @testdox Should report a duplicate when two different providers control the same wallet.
	 */
	public function test_two_providers_controlling_the_same_wallet_are_a_duplicate(): void {
		$this->register_fake_gateways(
			array(
				'acme_applepay'  => 'yes',
				'acme_wallets'   => 'yes',
				'other_applepay' => 'yes',
			)
		);
		$this->contribute_hints( array( 'apple_pay' => array( 'acme_wallets' ) ) );
		$this->contribute_control_units(
			array(
				// A joint unit, in the shape of a provider that controls both wallets with one flag.
				new ExpressControlUnit(
					'acme:wallets',
					'acme',
					'Acme',
					array( 'acme_applepay', 'acme_wallets' ),
					array( ExpressControlUnit::WALLET_APPLE_PAY, ExpressControlUnit::WALLET_GOOGLE_PAY ),
					true
				),
				// An independent unit, in the shape of a provider with per-wallet control.
				new ExpressControlUnit(
					'other:apple_pay',
					'other',
					'Other',
					array( 'other_applepay' ),
					array( ExpressControlUnit::WALLET_APPLE_PAY ),
					true
				),
			)
		);

		$result = $this->sut->detect();

		$this->assertArrayHasKey( 'apple_pay', $result['express'], 'Two providers offering Apple Pay is a duplicate.' );
		$this->assertEqualsCanonicalizing(
			array( 'acme_applepay', 'acme_wallets', 'other_applepay' ),
			$result['express']['apple_pay'],
			'Every contributing gateway id stays in the group; only the counting is per unit.'
		);
	}

	/**
	 * @testdox Should count gateways with no control unit individually.
	 */
	public function test_gateways_without_a_control_unit_count_individually(): void {
		$this->register_fake_gateways(
			array(
				'foo_applepay' => 'yes',
				'bar_applepay' => 'yes',
			)
		);
		// No control units contributed at all.

		$result = $this->sut->detect();

		$this->assertArrayHasKey(
			'apple_pay',
			$result['express'],
			'Without adapters, each gateway is its own implementation and the collision still reports.'
		);
	}

	/**
	 * @testdox Should ignore malformed control-unit providers without losing the valid ones.
	 */
	public function test_malformed_control_unit_providers_are_ignored(): void {
		$this->register_fake_gateways(
			array(
				'acme_applepay' => 'yes',
				'acme_wallets'  => 'yes',
			)
		);
		$this->contribute_hints( array( 'apple_pay' => array( 'acme_wallets' ) ) );

		add_filter(
			'woocommerce_express_checkout_control_unit_providers',
			static function () {
				return array(
					// Not a provider at all.
					'nonsense',
					// Throws when queried.
					new class() implements ExpressControlUnitProviderInterface {
						/**
						 * Always fails.
						 *
						 * @return ExpressControlUnit[]
						 */
						public function get_control_units(): array {
							$this->blow_up();

							return array();
						}

						/**
						 * Raise the failure this double exists to produce.
						 *
						 * @return void
						 * @throws \RuntimeException Always.
						 */
						private function blow_up(): void {
							throw new \RuntimeException( 'boom' );
						}
					},
					// Returns junk alongside one valid unit.
					new class() implements ExpressControlUnitProviderInterface {
						/**
						 * Returns a mix of invalid and valid units.
						 *
						 * @return array<mixed>
						 */
						public function get_control_units(): array {
							return array(
								'not-a-unit',
								// Invalid: no gateway ids and no wallets.
								new ExpressControlUnit( 'empty', 'x', 'X', array(), array(), true ),
								new ExpressControlUnit(
									'acme:wallets',
									'acme',
									'Acme',
									array( 'acme_applepay', 'acme_wallets' ),
									array( ExpressControlUnit::WALLET_APPLE_PAY ),
									true
								),
							);
						}
					},
				);
			}
		);

		$result = $this->sut->detect();

		$this->assertSame(
			array(),
			$result['express'],
			'The one valid unit still collapses both gateway ids, despite the malformed neighbours.'
		);
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
	 * Contribute express control units through a fake provider.
	 *
	 * Replaces the registered providers entirely, so core's WooPayments/PayPal adapters cannot
	 * influence the assertions.
	 *
	 * @param ExpressControlUnit[] $units The units the fake provider should report.
	 */
	private function contribute_control_units( array $units ): void {
		add_filter(
			'woocommerce_express_checkout_control_unit_providers',
			static function () use ( $units ) {
				return array(
					new class( $units ) implements ExpressControlUnitProviderInterface {
						/**
						 * The units to report.
						 *
						 * @var ExpressControlUnit[]
						 */
						private array $units;

						/**
						 * Build a fake provider reporting a fixed set of units.
						 *
						 * @param ExpressControlUnit[] $units The units to report.
						 */
						public function __construct( array $units ) {
							$this->units = $units;
						}

						/**
						 * The configured units.
						 *
						 * @return ExpressControlUnit[]
						 */
						public function get_control_units(): array {
							return $this->units;
						}
					},
				);
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
