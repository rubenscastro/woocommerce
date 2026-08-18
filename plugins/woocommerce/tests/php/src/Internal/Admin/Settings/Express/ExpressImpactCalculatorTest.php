<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\Express;

use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnit;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressImpactCalculator;
use WC_Unit_Test_Case;

/**
 * Tests for the ExpressImpactCalculator class.
 */
class ExpressImpactCalculatorTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var ExpressImpactCalculator
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new ExpressImpactCalculator();
	}

	/**
	 * The canonical store shape.
	 *
	 * Apple Pay is offered by a provider with a JOINT wallet unit (WooPayments-shaped) and one with an
	 * INDEPENDENT per-wallet unit (PayPal-shaped). Google Pay comes only from the joint provider.
	 *
	 * @param bool $joint_can_disable Whether the joint unit reports it can be turned off.
	 *
	 * @return ExpressControlUnit[]
	 */
	private function units( bool $joint_can_disable = true ): array {
		return array(
			new ExpressControlUnit(
				'joint:wallets',
				'joint-provider',
				'Joint Provider',
				array( 'joint_apple_pay', 'joint_google_pay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY, ExpressControlUnit::WALLET_GOOGLE_PAY ),
				true,
				$joint_can_disable
			),
			new ExpressControlUnit(
				'indep:apple_pay',
				'indep-provider',
				'Independent Provider',
				array( 'indep_apple_pay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY ),
				true
			),
		);
	}

	/**
	 * @testdox Should report the other wallet as a consequence when a joint unit is turned off.
	 */
	public function test_coupled_selection_reports_a_consequence(): void {
		$result = $this->sut->calculate( $this->units(), array( 'apple_pay' => 'indep-provider' ) );

		$this->assertSame( array( 'joint:wallets' ), $result['disable'], 'The joint unit must be turned off.' );
		$this->assertSame( array( 'google_pay' ), $result['lost_wallets'], 'Google Pay is lost with it.' );
		$this->assertCount( 1, $result['consequences'] );
		$this->assertSame( 'google_pay', $result['consequences'][0]['walletId'] );
		$this->assertSame( 'joint:wallets', $result['consequences'][0]['causedByUnitId'] );
		$this->assertSame( 'Joint Provider', $result['consequences'][0]['providerLabel'] );
		$this->assertSame( array(), $result['conflicts'], 'A consequence is not a conflict.' );
		$this->assertSame( array(), $result['blocked'] );
	}

	/**
	 * @testdox Should report no consequence when the disabled unit controls only the chosen wallet.
	 */
	public function test_uncoupled_selection_reports_no_consequence(): void {
		$result = $this->sut->calculate( $this->units(), array( 'apple_pay' => 'joint-provider' ) );

		$this->assertSame( array( 'indep:apple_pay' ), $result['disable'], 'Only the other provider is turned off.' );
		$this->assertSame( array(), $result['lost_wallets'], 'Both wallets are still provided.' );
		$this->assertSame( array(), $result['consequences'], 'Nothing collateral happens.' );
		$this->assertSame( array(), $result['conflicts'] );
	}

	/**
	 * @testdox Should report a conflict when one selection turns off the provider another selection chose.
	 */
	public function test_contradictory_selections_are_a_conflict(): void {
		$result = $this->sut->calculate(
			$this->units(),
			array(
				// Keep the joint provider for Apple Pay...
				'apple_pay'  => 'joint-provider',
				// ...while asking somebody else for Google Pay, which turns that same unit off.
				'google_pay' => 'indep-provider',
			)
		);

		$this->assertContains( 'joint:wallets', $result['disable'] );
		$this->assertNotEmpty( $result['conflicts'], 'The Apple Pay choice is contradicted.' );
		$this->assertSame( 'apple_pay', $result['conflicts'][0]['walletId'] );
		$this->assertSame( 'joint:wallets', $result['conflicts'][0]['unitId'] );
	}

	/**
	 * @testdox Should report a unit that must be turned off but says it cannot be.
	 */
	public function test_unit_that_cannot_be_disabled_is_blocked(): void {
		$result = $this->sut->calculate( $this->units( false ), array( 'apple_pay' => 'indep-provider' ) );

		$this->assertSame( array( 'joint:wallets' ), $result['blocked'] );
	}

	/**
	 * @testdox Should do nothing when there are no selections.
	 */
	public function test_no_selections_changes_nothing(): void {
		$result = $this->sut->calculate( $this->units(), array() );

		$this->assertSame( array(), $result['disable'] );
		$this->assertSame( array(), $result['lost_wallets'] );
		$this->assertSame( array(), $result['consequences'] );
		$this->assertSame( array(), $result['conflicts'] );
	}

	/**
	 * @testdox Should ignore an empty selection value rather than treating it as a choice.
	 */
	public function test_empty_selection_value_is_ignored(): void {
		$result = $this->sut->calculate( $this->units(), array( 'apple_pay' => '' ) );

		$this->assertSame( array(), $result['disable'], 'An untouched row is not a choice.' );
	}

	/**
	 * @testdox Should accept a control unit id as the selection, not only a provider slug.
	 */
	public function test_selection_can_name_the_control_unit(): void {
		$result = $this->sut->calculate( $this->units(), array( 'apple_pay' => 'indep:apple_pay' ) );

		$this->assertSame( array( 'joint:wallets' ), $result['disable'] );
		$this->assertSame( array( 'google_pay' ), $result['lost_wallets'] );
	}

	/**
	 * @testdox Should ignore units that are already disabled.
	 */
	public function test_disabled_units_are_not_considered(): void {
		$units = array(
			new ExpressControlUnit(
				'joint:wallets',
				'joint-provider',
				'Joint Provider',
				array( 'joint_apple_pay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY, ExpressControlUnit::WALLET_GOOGLE_PAY ),
				false
			),
			new ExpressControlUnit(
				'indep:apple_pay',
				'indep-provider',
				'Independent Provider',
				array( 'indep_apple_pay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY ),
				true
			),
		);

		$result = $this->sut->calculate( $units, array( 'apple_pay' => 'indep-provider' ) );

		$this->assertSame( array(), $result['disable'], 'An already disabled unit needs no action.' );
		$this->assertSame( array(), $result['consequences'], 'And cannot cause a consequence.' );
	}

	/**
	 * @testdox Should not report the wallet the merchant reassigned as a consequence of its own choice.
	 */
	public function test_reassigned_wallet_is_not_reported_as_collateral(): void {
		// Only the joint provider offers Google Pay, so choosing anyone else for it loses the wallet —
		// but that is the merchant's own request, not collateral damage.
		$result = $this->sut->calculate( $this->units(), array( 'google_pay' => 'indep-provider' ) );

		$this->assertContains( 'google_pay', $result['lost_wallets'] );
		$this->assertSame(
			array(),
			array_column( $result['consequences'], 'walletId' ),
			'The chosen wallet is never reported back as a consequence.'
		);
	}
}
