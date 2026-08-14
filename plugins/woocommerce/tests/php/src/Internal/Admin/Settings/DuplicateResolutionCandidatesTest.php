<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Internal\Admin\Settings\DuplicateResolutionCandidates;
use WC_Unit_Test_Case;

/**
 * Tests for the DuplicateResolutionCandidates class.
 */
class DuplicateResolutionCandidatesTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var DuplicateResolutionCandidates
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new DuplicateResolutionCandidates();
	}

	/**
	 * @testdox Should keep a duplicate group untouched when no provider hides its methods.
	 */
	public function test_ungrouped_duplicates_are_all_resolvable(): void {
		$result = $this->sut->compute(
			array( 'klarna' => array( 'a_klarna', 'b_klarna' ) ),
			array()
		);

		$this->assertArrayHasKey( 'klarna', $result['resolvable'], 'An ungrouped collision stays resolvable.' );
		$this->assertEqualsCanonicalizing( array( 'a_klarna', 'b_klarna' ), $result['resolvable']['klarna'], 'Both members remain.' );
		$this->assertSame( array(), $result['unresolvable'], 'Nothing is unresolvable.' );
	}

	/**
	 * @testdox Should drop children of a group that does not show its children (Optimized Checkout on).
	 */
	public function test_optimized_checkout_children_are_hidden_and_group_becomes_unresolvable(): void {
		$result = $this->sut->compute(
			array( 'klarna' => array( 'stripe_klarna', 'woocommerce_payments_klarna' ) ),
			array(
				'stripe' => array(
					'childGatewayIds'   => array( 'stripe_klarna' ),
					'showChildren'      => false,
					'optimizedCheckout' => true,
					'settingsUrl'       => 'https://example.test',
				),
			)
		);

		$this->assertArrayNotHasKey( 'klarna', $result['resolvable'], 'With only one surfaced implementation left, the group is not resolvable.' );
		$this->assertContains( 'klarna', $result['unresolvable'], 'The collision is reported as unresolvable, not silently dropped.' );
	}

	/**
	 * @testdox Should keep children individually resolvable when the group shows its children (Optimized Checkout off).
	 */
	public function test_children_are_resolvable_when_group_shows_children(): void {
		$result = $this->sut->compute(
			array( 'klarna' => array( 'stripe_klarna', 'woocommerce_payments_klarna' ) ),
			array(
				'stripe' => array(
					'childGatewayIds'   => array( 'stripe_klarna' ),
					'showChildren'      => true,
					'optimizedCheckout' => false,
					'settingsUrl'       => 'https://example.test',
				),
			)
		);

		$this->assertArrayHasKey( 'klarna', $result['resolvable'], 'With children shown, the collision stays resolvable.' );
		$this->assertContains( 'stripe_klarna', $result['resolvable']['klarna'], 'The shown child remains an option.' );
	}

	/**
	 * @testdox Should hide the aggregate parent of an Optimized Checkout group.
	 */
	public function test_optimized_checkout_parent_is_hidden(): void {
		$result = $this->sut->compute(
			array( 'card' => array( 'stripe', 'woocommerce_payments' ) ),
			array(
				'stripe' => array(
					'childGatewayIds'   => array( 'stripe_klarna' ),
					'showChildren'      => false,
					'optimizedCheckout' => true,
					'settingsUrl'       => 'https://example.test',
				),
			)
		);

		$this->assertArrayNotHasKey( 'card', $result['resolvable'], 'The Optimized Checkout parent is not an individually resolvable method.' );
		$this->assertContains( 'card', $result['unresolvable'], 'The card collision is reported as unresolvable.' );
	}
}
