<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\Express;

use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnit;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnitDisablerInterface;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnitProviderInterface;
use Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressDuplicatesResolver;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Tests for the ExpressDuplicatesResolver class.
 */
class ExpressDuplicatesResolverTest extends WC_Unit_Test_Case {

	/**
	 * Control unit ids the fake disabler was asked to turn off.
	 *
	 * @var string[]
	 */
	private $disabled = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->disabled = array();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_payment_gateways' );
		remove_all_filters( 'woocommerce_payment_method_duplicate_gateway_hints' );
		remove_all_filters( 'woocommerce_express_checkout_control_unit_providers' );
		remove_all_filters( 'woocommerce_express_checkout_control_unit_disablers' );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		parent::tearDown();
	}

	/**
	 * The canonical scenario, wired end to end.
	 *
	 * Apple Pay is duplicated across a JOINT unit (which also carries Google Pay) and an INDEPENDENT
	 * unit. Google Pay comes only from the joint unit, so it is not itself duplicated.
	 *
	 * @param bool $joint_can_disable Whether the joint unit reports it can be turned off.
	 */
	private function set_up_canonical_store( bool $joint_can_disable = true ): void {
		$this->register_fake_gateways(
			array(
				'joint_applepay' => 'yes',
				'indep_applepay' => 'yes',
			)
		);

		$units = array(
			new ExpressControlUnit(
				'joint:wallets',
				'joint-provider',
				'Joint Provider',
				array( 'joint_applepay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY, ExpressControlUnit::WALLET_GOOGLE_PAY ),
				true,
				$joint_can_disable
			),
			new ExpressControlUnit(
				'indep:apple_pay',
				'indep-provider',
				'Independent Provider',
				array( 'indep_applepay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY ),
				true
			),
		);

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
						 * Constructor.
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
	 * A store where BOTH wallets are duplicated, so two selections exist and can contradict.
	 *
	 * A selection is only accepted for a wallet that is currently duplicated, so a conflict is only
	 * reachable when both wallets have more than one provider.
	 */
	private function set_up_both_duplicated_store(): void {
		$this->register_fake_gateways(
			array(
				'joint_applepay'  => 'yes',
				'joint_googlepay' => 'yes',
				'indep_applepay'  => 'yes',
				'other_googlepay' => 'yes',
			)
		);

		$units = array(
			new ExpressControlUnit(
				'joint:wallets',
				'joint-provider',
				'Joint Provider',
				array( 'joint_applepay', 'joint_googlepay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY, ExpressControlUnit::WALLET_GOOGLE_PAY ),
				true
			),
			new ExpressControlUnit(
				'indep:apple_pay',
				'indep-provider',
				'Independent Provider',
				array( 'indep_applepay' ),
				array( ExpressControlUnit::WALLET_APPLE_PAY ),
				true
			),
			new ExpressControlUnit(
				'other:google_pay',
				'other-provider',
				'Other Provider',
				array( 'other_googlepay' ),
				array( ExpressControlUnit::WALLET_GOOGLE_PAY ),
				true
			),
		);

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
						 * Constructor.
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
	 * Register a disabler that records what it was asked to turn off.
	 *
	 * @param string $status The status it should report.
	 */
	private function register_recording_disabler( string $status = 'disabled' ): void {
		$recorder = function ( string $unit_id ) {
			$this->disabled[] = $unit_id;
		};

		add_filter(
			'woocommerce_express_checkout_control_unit_disablers',
			static function () use ( $recorder, $status ) {
				return array(
					new class( $recorder, $status ) implements ExpressControlUnitDisablerInterface {
						/**
						 * Records the call.
						 *
						 * @var callable
						 */
						private $recorder;

						/**
						 * The status to report.
						 *
						 * @var string
						 */
						private string $status;

						/**
						 * Constructor.
						 *
						 * @param callable $recorder Records the call.
						 * @param string   $status   The status to report.
						 */
						public function __construct( callable $recorder, string $status ) {
							$this->recorder = $recorder;
							$this->status   = $status;
						}

						/**
						 * Claims every unit.
						 *
						 * @param string $control_unit_id The unit id.
						 *
						 * @return bool
						 */
						public function supports( string $control_unit_id ): bool {
							unset( $control_unit_id );
							// Avoid parameter not used PHPCS errors.
							return true;
						}

						/**
						 * Records and reports the configured status.
						 *
						 * @param string $control_unit_id The unit id.
						 *
						 * @return array{status: string, message?: string}
						 */
						public function disable( string $control_unit_id ): array {
							( $this->recorder )( $control_unit_id );

							return array( 'status' => $this->status );
						}
					},
				);
			}
		);
	}

	/**
	 * @testdox Should disable the joint unit and report the coupled wallet as lost.
	 */
	public function test_coupled_selection_disables_the_joint_unit(): void {
		$this->set_up_canonical_store();
		$this->register_recording_disabler();

		$report = ( new ExpressDuplicatesResolver() )->resolve( array( 'apple_pay' => 'indep:apple_pay' ) );

		$this->assertTrue( $report['success'], 'The resolution should succeed.' );
		$this->assertSame( array( 'joint:wallets' ), $this->disabled, 'Only the deselected unit is turned off.' );
		$this->assertSame( array( 'google_pay' ), $report['lost_wallets'], 'The coupled wallet is reported as lost.' );
		$this->assertSame( 'disabled', $report['disabled'][0]['status'] );
	}

	/**
	 * @testdox Should refuse contradictory selections without disabling anything.
	 */
	public function test_conflicting_selections_are_refused(): void {
		$this->set_up_both_duplicated_store();
		$this->register_recording_disabler();

		$report = ( new ExpressDuplicatesResolver() )->resolve(
			array(
				// Keep the joint provider for Apple Pay...
				'apple_pay'  => 'joint:wallets',
				// ...while asking someone else for Google Pay, which turns that same unit off.
				'google_pay' => 'other:google_pay',
			)
		);

		$this->assertFalse( $report['success'] );
		$this->assertSame( 'conflicting_selections', $report['error'] );
		$this->assertSame( array(), $this->disabled, 'Nothing may be mutated when the request is refused.' );
	}

	/**
	 * @testdox Should refuse when a unit that must be turned off reports it cannot be.
	 */
	public function test_non_disableable_unit_is_refused(): void {
		$this->set_up_canonical_store( false );
		$this->register_recording_disabler();

		$report = ( new ExpressDuplicatesResolver() )->resolve( array( 'apple_pay' => 'indep:apple_pay' ) );

		$this->assertFalse( $report['success'] );
		$this->assertSame( 'not_disableable', $report['error'] );
		$this->assertSame( array(), $this->disabled );
	}

	/**
	 * @testdox Should refuse a selection for a wallet that is no longer duplicated.
	 */
	public function test_selection_for_a_non_duplicated_wallet_is_refused(): void {
		$this->set_up_canonical_store();
		$this->register_recording_disabler();

		// Google Pay comes from one provider only, so it is not a duplicate and cannot be resolved.
		$report = ( new ExpressDuplicatesResolver() )->resolve( array( 'google_pay' => 'indep-provider' ) );

		$this->assertFalse( $report['success'] );
		$this->assertSame( 'nothing_to_resolve', $report['error'] );
		$this->assertSame( array(), $this->disabled );
	}

	/**
	 * @testdox Should refuse when the caller's preview no longer matches the recomputed impact.
	 */
	public function test_stale_preview_is_refused(): void {
		$this->set_up_canonical_store();
		$this->register_recording_disabler();

		// The caller believed nothing would be lost, but Google Pay would be.
		$report = ( new ExpressDuplicatesResolver() )->resolve(
			array( 'apple_pay' => 'indep:apple_pay' ),
			array()
		);

		$this->assertFalse( $report['success'] );
		$this->assertSame( 'stale_preview', $report['error'] );
		$this->assertSame( array(), $this->disabled, 'A stale request must not mutate anything.' );
	}

	/**
	 * @testdox Should accept a matching preview.
	 */
	public function test_matching_preview_is_accepted(): void {
		$this->set_up_canonical_store();
		$this->register_recording_disabler();

		$report = ( new ExpressDuplicatesResolver() )->resolve(
			array( 'apple_pay' => 'indep:apple_pay' ),
			array( 'google_pay' )
		);

		$this->assertTrue( $report['success'] );
		$this->assertSame( array( 'joint:wallets' ), $this->disabled );
	}

	/**
	 * @testdox Should report failure when no integration claims the control unit.
	 */
	public function test_unclaimed_control_unit_is_unsupported(): void {
		$this->set_up_canonical_store();

		add_filter(
			'woocommerce_express_checkout_control_unit_disablers',
			static function () {
				return array();
			}
		);

		$report = ( new ExpressDuplicatesResolver() )->resolve( array( 'apple_pay' => 'indep:apple_pay' ) );

		$this->assertFalse( $report['success'] );
		$this->assertSame( 'unsupported', $report['disabled'][0]['status'] );
	}

	/**
	 * @testdox Should report an unsuccessful resolution when the integration fails.
	 */
	public function test_failing_disabler_is_reported(): void {
		$this->set_up_canonical_store();
		$this->register_recording_disabler( 'failed' );

		$report = ( new ExpressDuplicatesResolver() )->resolve( array( 'apple_pay' => 'indep:apple_pay' ) );

		$this->assertFalse( $report['success'] );
		$this->assertSame( 'failed', $report['disabled'][0]['status'] );
	}

	/**
	 * Register a deterministic set of fake payment gateways.
	 *
	 * @param array<string, string> $specs Map of gateway id to enabled flag ('yes'|'no').
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
