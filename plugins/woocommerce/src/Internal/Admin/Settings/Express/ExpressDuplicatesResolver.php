<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodDuplicatesDetector;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a merchant's express wallet choices.
 *
 * The client previews the impact of a choice so the merchant can see the consequences before
 * confirming. That preview is a convenience, never an instruction: this class re-detects duplicates
 * and re-reads the control units from live state, recomputes the impact itself, and acts only on its
 * own result. A store can change between the modal being opened and Apply being pressed — another
 * admin toggles a wallet, a provider's account state changes — and applying a stale plan could turn
 * off something the merchant was never shown.
 *
 * Refuses rather than guesses:
 *
 * - a wallet that is no longer duplicated is not resolved;
 * - contradictory choices are refused outright, since no ordering of them is correct;
 * - a unit that reports it cannot be turned off is refused;
 * - if the caller states which wallets it expected to lose and the recomputed set differs, the whole
 *   request is refused as stale.
 *
 * @internal
 *
 * @since 11.1.0
 */
class ExpressDuplicatesResolver {

	/**
	 * The duplicate detector.
	 *
	 * @var PaymentMethodDuplicatesDetector
	 */
	private PaymentMethodDuplicatesDetector $detector;

	/**
	 * The control unit collector.
	 *
	 * @var ExpressControlUnits
	 */
	private ExpressControlUnits $control_units;

	/**
	 * The impact calculator.
	 *
	 * @var ExpressImpactCalculator
	 */
	private ExpressImpactCalculator $calculator;

	/**
	 * The shared control-unit disabler.
	 *
	 * @var ExpressControlUnitDisablers
	 */
	private ExpressControlUnitDisablers $disablers;

	/**
	 * Constructor.
	 *
	 * @param PaymentMethodDuplicatesDetector|null $detector      The duplicate detector.
	 * @param ExpressControlUnits|null             $control_units The control unit collector.
	 * @param ExpressImpactCalculator|null         $calculator    The impact calculator.
	 */
	public function __construct(
		?PaymentMethodDuplicatesDetector $detector = null,
		?ExpressControlUnits $control_units = null,
		?ExpressImpactCalculator $calculator = null
	) {
		$this->control_units = $control_units ?? new ExpressControlUnits();
		$this->detector      = $detector ?? new PaymentMethodDuplicatesDetector( $this->control_units );
		$this->calculator    = $calculator ?? new ExpressImpactCalculator();
		$this->disablers     = new ExpressControlUnitDisablers();
	}

	/**
	 * Apply the merchant's express wallet choices.
	 *
	 * @param array<string, string> $selections           Chosen control unit id (or provider slug)
	 *        keyed by canonical wallet id.
	 * @param string[]|null         $expected_lost_wallets Wallets the caller was shown as being lost.
	 *        When provided, a mismatch against the recomputed set refuses the request as stale.
	 *
	 * @return array{
	 *     success: bool,
	 *     error: string|null,
	 *     disabled: array<int, array{controlUnitId: string, status: string, message: string}>,
	 *     lost_wallets: string[],
	 *     duplicates: array<string, string[]>
	 * }
	 */
	public function resolve( array $selections, ?array $expected_lost_wallets = null ): array {
		try {
			$units      = $this->control_units->get_units();
			$duplicates = $this->detector->detect()['express'];

			// Only wallets that are still duplicated right now may be resolved.
			$selections = $this->keep_current_duplicates( $selections, $duplicates );

			if ( empty( $selections ) ) {
				return $this->failure( 'nothing_to_resolve', $duplicates );
			}

			$impact = $this->calculator->calculate( $units, $selections );

			if ( ! empty( $impact['conflicts'] ) ) {
				return $this->failure( 'conflicting_selections', $duplicates );
			}

			if ( ! empty( $impact['blocked'] ) ) {
				return $this->failure( 'not_disableable', $duplicates );
			}

			if ( null !== $expected_lost_wallets && ! $this->same_set( $expected_lost_wallets, $impact['lost_wallets'] ) ) {
				// What the merchant confirmed is not what would happen now.
				return $this->failure( 'stale_preview', $duplicates );
			}

			if ( empty( $impact['disable'] ) ) {
				return $this->failure( 'nothing_to_resolve', $duplicates );
			}

			$results = array();
			$all_ok  = true;

			foreach ( $impact['disable'] as $unit_id ) {
				$outcome = $this->disablers->disable( (string) $unit_id );

				$results[] = array(
					'controlUnitId' => (string) $unit_id,
					'status'        => $outcome['status'],
					'message'       => $outcome['message'] ?? '',
				);

				if ( 'disabled' !== $outcome['status'] ) {
					$all_ok = false;
				}
			}

			return array(
				'success'      => $all_ok,
				'error'        => null,
				'disabled'     => $results,
				'lost_wallets' => $impact['lost_wallets'],
				// Freshly detected after the mutation, so the caller sees the real post-state.
				'duplicates'   => $this->detector->detect()['express'],
			);
		} catch ( Throwable $e ) {
			return $this->failure( 'unexpected_error', array() );
		}
	}

	/**
	 * Drop selections for wallets that are not currently duplicated.
	 *
	 * @param array<string, string>   $selections The submitted selections.
	 * @param array<string, string[]> $duplicates The freshly detected express duplicates.
	 *
	 * @return array<string, string>
	 */
	private function keep_current_duplicates( array $selections, array $duplicates ): array {
		$kept = array();

		foreach ( $selections as $wallet_id => $chosen ) {
			$wallet_id = (string) $wallet_id;
			$chosen    = (string) $chosen;

			if ( '' !== $chosen && isset( $duplicates[ $wallet_id ] ) ) {
				$kept[ $wallet_id ] = $chosen;
			}
		}

		return $kept;
	}

	/**
	 * Whether two wallet lists contain the same members, order aside.
	 *
	 * @param string[] $expected The expected list.
	 * @param string[] $actual   The recomputed list.
	 *
	 * @return bool
	 */
	private function same_set( array $expected, array $actual ): bool {
		$expected = array_unique( array_map( 'strval', $expected ) );
		$actual   = array_unique( array_map( 'strval', $actual ) );

		sort( $expected );
		sort( $actual );

		return $expected === $actual;
	}

	/**
	 * A refusal, carrying the current duplicates so the caller can resync.
	 *
	 * @param string                  $error      The refusal reason.
	 * @param array<string, string[]> $duplicates The current express duplicates.
	 *
	 * @return array{success: bool, error: string, disabled: array<int, mixed>, lost_wallets: string[], duplicates: array<string, string[]>}
	 */
	private function failure( string $error, array $duplicates ): array {
		return array(
			'success'      => false,
			'error'        => $error,
			'disabled'     => array(),
			'lost_wallets' => array(),
			'duplicates'   => $duplicates,
		);
	}
}
