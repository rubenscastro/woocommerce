<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what a merchant's express wallet choices would actually do.
 *
 * The merchant chooses at the level of "this wallet, from this provider". The system can only act at
 * the level of control units. This class is the translation between the two, and it is the only place
 * that answers "what else breaks if I do this".
 *
 * It is deliberately pure: units in, verdict out, no gateways read, no options written, no globals.
 * That makes the same code usable for the modal's live preview and for the authoritative re-check at
 * apply time, and makes every scenario testable without a store.
 *
 * Three outcomes are kept apart, because they mean different things to the merchant:
 *
 * - **consequences** — wallets that disappear as a side effect of a valid choice. Expected and
 *   allowed: a provider that controls Apple Pay and Google Pay together loses both when it is turned
 *   off for Apple Pay. The merchant is told and may continue.
 * - **conflicts** — choices that contradict each other: the provider chosen for one wallet is turned
 *   off by the choice made for another. There is no coherent outcome, so this must block.
 * - **blocked** — a unit that would have to be turned off but reports it cannot be.
 *
 * @internal
 *
 * @since 11.1.0
 */
class ExpressImpactCalculator {

	/**
	 * Calculate the effect of a set of wallet → provider selections.
	 *
	 * @param ExpressControlUnit[]  $units      All known control units.
	 * @param array<string, string> $selections Chosen provider slug (or control unit id) keyed by
	 *        canonical wallet id. Entries with an empty value are ignored, so an untouched row is
	 *        simply not a choice.
	 *
	 * @return array{
	 *     disable: string[],
	 *     remaining: string[],
	 *     lost_wallets: string[],
	 *     consequences: array<int, array{walletId: string, causedByUnitId: string, providerLabel: string}>,
	 *     conflicts: array<int, array{walletId: string, unitId: string}>,
	 *     blocked: string[]
	 * }
	 */
	public function calculate( array $units, array $selections ): array {
		$enabled    = $this->enabled_units( $units );
		$selections = $this->clean_selections( $selections );

		$available_before = $this->wallets_provided_by( $enabled );

		$to_disable = array();

		foreach ( $enabled as $unit ) {
			if ( $this->is_deselected( $unit, $selections ) ) {
				$to_disable[ $unit->get_id() ] = $unit;
			}
		}

		$remaining = array();

		foreach ( $enabled as $unit ) {
			if ( ! isset( $to_disable[ $unit->get_id() ] ) ) {
				$remaining[] = $unit;
			}
		}

		$available_after = $this->wallets_provided_by( $remaining );
		$lost            = array_values( array_diff( $available_before, $available_after ) );

		return array(
			'disable'      => array_keys( $to_disable ),
			'remaining'    => array_map(
				static function ( ExpressControlUnit $unit ): string {
					return $unit->get_id();
				},
				$remaining
			),
			'lost_wallets' => $lost,
			'consequences' => $this->build_consequences( $lost, $selections, $to_disable ),
			'conflicts'    => $this->build_conflicts( $selections, $to_disable ),
			'blocked'      => $this->build_blocked( $to_disable ),
		);
	}

	/**
	 * Whether a unit must be turned off given the selections.
	 *
	 * A unit is turned off when the merchant chose somebody else for any wallet it provides. Note
	 * that this is what produces coupling: the unit goes, and every other wallet it carries goes with
	 * it, without that ever being asked for directly.
	 *
	 * @param ExpressControlUnit    $unit       The unit to test.
	 * @param array<string, string> $selections Cleaned selections.
	 *
	 * @return bool
	 */
	private function is_deselected( ExpressControlUnit $unit, array $selections ): bool {
		foreach ( $selections as $wallet_id => $chosen ) {
			if ( $unit->provides_wallet( $wallet_id ) && ! $this->matches_selection( $unit, $chosen ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a unit is the one the merchant chose.
	 *
	 * A selection may name either the provider slug or the control unit id, so the modal can send
	 * whichever identity it rendered without the two sides having to agree on one.
	 *
	 * @param ExpressControlUnit $unit   The unit to test.
	 * @param string             $chosen The chosen provider slug or unit id.
	 *
	 * @return bool
	 */
	private function matches_selection( ExpressControlUnit $unit, string $chosen ): bool {
		return $chosen === $unit->get_id() || $chosen === $unit->get_provider_slug();
	}

	/**
	 * Describe each lost wallet the merchant did not ask to lose.
	 *
	 * A wallet the merchant actively reassigned is not a consequence — it is the request. Only
	 * collateral loss is reported.
	 *
	 * @param string[]                          $lost       Wallet ids no longer provided.
	 * @param array<string, string>             $selections Cleaned selections.
	 * @param array<string, ExpressControlUnit> $to_disable Units being turned off, keyed by id.
	 *
	 * @return array<int, array{walletId: string, causedByUnitId: string, providerLabel: string}>
	 */
	private function build_consequences( array $lost, array $selections, array $to_disable ): array {
		$consequences = array();

		foreach ( $lost as $wallet_id ) {
			if ( isset( $selections[ $wallet_id ] ) ) {
				continue;
			}

			foreach ( $to_disable as $unit ) {
				if ( $unit->provides_wallet( $wallet_id ) ) {
					$consequences[] = array(
						'walletId'       => $wallet_id,
						'causedByUnitId' => $unit->get_id(),
						'providerLabel'  => $unit->get_provider_label(),
					);
					break;
				}
			}
		}

		return $consequences;
	}

	/**
	 * Find selections whose chosen provider is turned off by another selection.
	 *
	 * @param array<string, string>             $selections Cleaned selections.
	 * @param array<string, ExpressControlUnit> $to_disable Units being turned off, keyed by id.
	 *
	 * @return array<int, array{walletId: string, unitId: string}>
	 */
	private function build_conflicts( array $selections, array $to_disable ): array {
		$conflicts = array();

		foreach ( $selections as $wallet_id => $chosen ) {
			foreach ( $to_disable as $unit ) {
				if ( $unit->provides_wallet( $wallet_id ) && $this->matches_selection( $unit, $chosen ) ) {
					$conflicts[] = array(
						'walletId' => $wallet_id,
						'unitId'   => $unit->get_id(),
					);
					break;
				}
			}
		}

		return $conflicts;
	}

	/**
	 * The units that would have to be turned off but report that they cannot be.
	 *
	 * @param array<string, ExpressControlUnit> $to_disable Units being turned off, keyed by id.
	 *
	 * @return string[]
	 */
	private function build_blocked( array $to_disable ): array {
		$blocked = array();

		foreach ( $to_disable as $unit ) {
			if ( ! $unit->can_disable() ) {
				$blocked[] = $unit->get_id();
			}
		}

		return $blocked;
	}

	/**
	 * The currently enabled units.
	 *
	 * @param ExpressControlUnit[] $units All known units.
	 *
	 * @return ExpressControlUnit[]
	 */
	private function enabled_units( array $units ): array {
		return array_values(
			array_filter(
				$units,
				static function ( ExpressControlUnit $unit ): bool {
					return $unit->is_enabled();
				}
			)
		);
	}

	/**
	 * The wallet ids provided by a set of units.
	 *
	 * @param ExpressControlUnit[] $units The units.
	 *
	 * @return string[]
	 */
	private function wallets_provided_by( array $units ): array {
		$wallets = array();

		foreach ( $units as $unit ) {
			foreach ( $unit->get_wallets() as $wallet_id ) {
				$wallets[ $wallet_id ] = true;
			}
		}

		return array_keys( $wallets );
	}

	/**
	 * Drop empty selections, so an untouched row is not treated as a choice.
	 *
	 * @param array<string, string> $selections Raw selections.
	 *
	 * @return array<string, string>
	 */
	private function clean_selections( array $selections ): array {
		$cleaned = array();

		foreach ( $selections as $wallet_id => $chosen ) {
			$wallet_id = (string) $wallet_id;
			$chosen    = (string) $chosen;

			if ( '' !== $wallet_id && '' !== $chosen ) {
				$cleaned[ $wallet_id ] = $chosen;
			}
		}

		return $cleaned;
	}
}
