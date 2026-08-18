<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Internal\Admin\Settings\BlocksPaymentMethodsSpikeController;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnit;
use ReflectionClass;
use WC_Unit_Test_Case;

/**
 * Tests for the express portion of the payload published by BlocksPaymentMethodsSpikeController.
 */
class BlocksPaymentMethodsSpikeControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var BlocksPaymentMethodsSpikeController
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new BlocksPaymentMethodsSpikeController();
	}

	/**
	 * Invoke a private method on the controller.
	 *
	 * The payload builders are private because the controller's only public surface is the hook it
	 * registers; the payload shape is what the client contracts on, so it is asserted directly.
	 *
	 * @param string       $method The method name.
	 * @param array<mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	private function invoke( string $method, array $args ) {
		$reflection = new ReflectionClass( $this->sut );
		$callable   = $reflection->getMethod( $method );
		$callable->setAccessible( true );

		return $callable->invokeArgs( $this->sut, $args );
	}

	/**
	 * The canonical pair: a joint unit carrying both wallets, and an independent per-wallet unit.
	 *
	 * @return ExpressControlUnit[]
	 */
	private function canonical_units(): array {
		return array(
			new ExpressControlUnit(
				'joint:wallets',
				'joint-plugin',
				'Joint Provider',
				array( 'joint_apple_pay', 'joint_master' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY, ExpressControlUnit::WALLET_GOOGLE_PAY ),
				true
			),
			new ExpressControlUnit(
				'indep:apple_pay',
				'indep-plugin',
				'Independent Provider',
				array( 'indep_apple_pay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY ),
				true
			),
		);
	}

	/**
	 * @testdox Should offer one decision covering every method a provider binds together.
	 */
	public function test_bound_methods_become_one_decision(): void {
		$result = $this->invoke(
			'get_express_duplicate_groups',
			array(
				array( 'apple_pay' => array( 'joint_apple_pay', 'joint_master', 'indep_apple_pay' ) ),
				$this->canonical_units(),
				array(),
				array(),
				array(),
			)
		);

		$this->assertCount( 1, $result, 'One decision, not one per method.' );
		$this->assertEqualsCanonicalizing(
			array( 'apple_pay', 'google_pay' ),
			$result[0]['walletIds'],
			'Google Pay is bound to Apple Pay by the joint unit, so it is decided here too.'
		);
		$this->assertSame( 'Apple Pay and Google Pay', $result[0]['label'] );
	}

	/**
	 * @testdox Should offer one option per provider, however many units or gateways it contributes.
	 */
	public function test_options_are_providers_not_units(): void {
		$result = $this->invoke(
			'get_express_duplicate_groups',
			array(
				array( 'apple_pay' => array( 'joint_apple_pay', 'joint_master', 'indep_apple_pay' ) ),
				$this->canonical_units(),
				array(),
				array(),
				array(),
			)
		);

		$slugs = array_column( $result[0]['options'], 'providerSlug' );

		$this->assertEqualsCanonicalizing( array( 'joint-plugin', 'indep-plugin' ), $slugs );
	}

	/**
	 * @testdox Should record how much of the decision each provider actually covers.
	 */
	public function test_options_declare_their_coverage(): void {
		$result = $this->invoke(
			'get_express_duplicate_groups',
			array(
				array( 'apple_pay' => array( 'joint_apple_pay', 'indep_apple_pay' ) ),
				$this->canonical_units(),
				array(),
				array(),
				array(),
			)
		);

		$by_slug = array_column( $result[0]['options'], null, 'providerSlug' );

		$this->assertEqualsCanonicalizing(
			array( 'apple_pay', 'google_pay' ),
			$by_slug['joint-plugin']['covers'],
			'The joint provider carries the whole decision.'
		);
		$this->assertSame(
			array( 'apple_pay' ),
			$by_slug['indep-plugin']['covers'],
			'The partial provider carries only Apple Pay — the rest is what gets disabled.'
		);
	}

	/**
	 * @testdox Should tell a method the provider has switched off apart from one it does not offer.
	 */
	public function test_options_declare_switched_off_support(): void {
		// The independent provider also offers Google Pay — the merchant simply has it turned off, so
		// it never reaches the detector's enabled-gateway output.
		$units = $this->canonical_units();

		$units[] = new ExpressControlUnit(
			'indep:google_pay',
			'indep-plugin',
			'Independent Provider',
			array( 'indep_google_pay' ),
			array( ExpressControlUnit::WALLET_GOOGLE_PAY ),
			false
		);

		$result = $this->invoke(
			'get_express_duplicate_groups',
			array(
				array( 'apple_pay' => array( 'joint_apple_pay', 'indep_apple_pay' ) ),
				$units,
				array(),
				array(),
				array(),
			)
		);

		$by_slug = array_column( $result[0]['options'], null, 'providerSlug' );

		$this->assertSame(
			array( 'apple_pay' ),
			$by_slug['indep-plugin']['covers'],
			'Google Pay is switched off, so it is not carried right now.'
		);
		$this->assertSame(
			array( 'google_pay' ),
			$by_slug['indep-plugin']['supportsDisabled'],
			'But it is offered, so the merchant can keep it by enabling it rather than losing it.'
		);
		$this->assertSame(
			array(),
			$by_slug['joint-plugin']['supportsDisabled'],
			'A provider carrying the whole decision has nothing switched off to report.'
		);
	}

	/**
	 * @testdox Should not claim switched-off support for a method the provider has no unit for.
	 */
	public function test_options_do_not_invent_switched_off_support(): void {
		$result = $this->invoke(
			'get_express_duplicate_groups',
			array(
				array( 'apple_pay' => array( 'joint_apple_pay', 'indep_apple_pay' ) ),
				$this->canonical_units(),
				array(),
				array(),
				array(),
			)
		);

		$by_slug = array_column( $result[0]['options'], null, 'providerSlug' );

		$this->assertSame(
			array(),
			$by_slug['indep-plugin']['supportsDisabled'],
			'No unit for Google Pay means nothing is known about it, which is not the same as offering it.'
		);
	}

	/**
	 * @testdox Should keep unrelated methods in separate decisions.
	 */
	public function test_unrelated_methods_are_separate_decisions(): void {
		$units = array(
			new ExpressControlUnit(
				'a:apple_pay',
				'a-plugin',
				'A',
				array( 'a_apple_pay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY ),
				true
			),
			new ExpressControlUnit(
				'b:apple_pay',
				'b-plugin',
				'B',
				array( 'b_apple_pay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY ),
				true
			),
			new ExpressControlUnit(
				'c:google_pay',
				'c-plugin',
				'C',
				array( 'c_google_pay' ),
				array( ExpressControlUnit::WALLET_GOOGLE_PAY ),
				true
			),
			new ExpressControlUnit(
				'd:google_pay',
				'd-plugin',
				'D',
				array( 'd_google_pay' ),
				array( ExpressControlUnit::WALLET_GOOGLE_PAY ),
				true
			),
		);

		$result = $this->invoke(
			'get_express_duplicate_groups',
			array(
				array(
					'apple_pay'  => array( 'a_apple_pay', 'b_apple_pay' ),
					'google_pay' => array( 'c_google_pay', 'd_google_pay' ),
				),
				$units,
				array(),
				array(),
				array(),
			)
		);

		$this->assertCount( 2, $result, 'Nothing binds these methods, so they are decided separately.' );
		$this->assertSame( 'Apple Pay', $result[0]['label'] );
		$this->assertSame( 'Google Pay', $result[1]['label'] );
	}

	/**
	 * @testdox Should not offer a decision when a participant has no control unit to turn off.
	 */
	public function test_decision_with_an_unresolvable_participant_is_omitted(): void {
		$result = $this->invoke(
			'get_express_duplicate_groups',
			array(
				array( 'apple_pay' => array( 'joint_apple_pay', 'unclaimed_applepay' ) ),
				$this->canonical_units(),
				array( 'unclaimed_applepay' => 'some-plugin' ),
				array(),
				array(),
			)
		);

		$this->assertSame(
			array(),
			$result,
			'A provider that cannot be turned off could be kept but never dropped, so the decision is not offered at all.'
		);
	}

	/**
	 * @testdox Should return no express decisions when there are no express duplicates.
	 */
	public function test_no_express_duplicates_yields_no_decisions(): void {
		$this->assertSame(
			array(),
			$this->invoke( 'get_express_duplicate_groups', array( array(), $this->canonical_units(), array(), array(), array() ) )
		);
	}

	/**
	 * @testdox Should serialize control units with their wallets and enabled state.
	 */
	public function test_control_units_are_serialized_for_the_client(): void {
		$serialized = $this->invoke( 'serialize_control_units', array( $this->canonical_units() ) );

		$this->assertCount( 2, $serialized );

		$by_id = array_column( $serialized, null, 'id' );

		$this->assertArrayHasKey( 'joint:wallets', $by_id );
		$this->assertSame( 'Joint Provider', $by_id['joint:wallets']['providerLabel'] );
		$this->assertEqualsCanonicalizing(
			array( 'apple_pay', 'google_pay' ),
			$by_id['joint:wallets']['wallets']
		);
		$this->assertTrue( $by_id['joint:wallets']['enabled'] );
		$this->assertTrue( $by_id['joint:wallets']['canDisable'] );
	}
}
