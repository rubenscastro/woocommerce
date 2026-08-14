<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Narrows the detector's regular duplicate groups down to the ones Step 1 can safely resolve.
 *
 * The detector reports every enabled implementation of a canonical method, but resolution must only
 * ever touch implementations the settings page represents as individually manageable regular checkout
 * methods. The single authority on that representation is the grouped-providers map (see
 * {@see StripeOptimizedCheckoutAdapter}): when a provider renders its methods as an aggregate — Stripe
 * with Optimized Checkout on — its individual methods are intentionally not surfaced, so they must not
 * be offered, selected, or mutated here.
 *
 * This class applies that rule generically from the grouped-providers structure alone (no provider
 * names, no OC-specific branching): an implementation is hidden when it is a child of a group that
 * does not show its children, or it is the aggregate parent of an Optimized-Checkout group. Reusing it
 * on both the server payload and the resolver keeps the representation rule in one place.
 *
 * @internal
 *
 * @since 11.1.0
 */
class DuplicateResolutionCandidates {

	/**
	 * Compute the resolvable regular duplicate groups and the ones the representation makes unresolvable.
	 *
	 * @param array<string, string[]>                                                                                           $regular_groups   The detector's `payment_methods` output (canonical id => enabled gateway ids).
	 * @param array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}> $grouped_providers The grouped-providers representation, keyed by parent gateway id.
	 *
	 * @return array{resolvable: array<string, string[]>, unresolvable: string[]} `resolvable` keeps only
	 *         canonical methods that still have two or more surfaced implementations; `unresolvable`
	 *         lists canonical ids that were duplicated but dropped below two once hidden implementations
	 *         were removed (e.g. a collision whose only alternative is an Optimized-Checkout child).
	 */
	public function compute( array $regular_groups, array $grouped_providers ): array {
		$hidden = $this->hidden_gateway_ids( $grouped_providers );

		$resolvable   = array();
		$unresolvable = array();

		foreach ( $regular_groups as $canonical_id => $gateway_ids ) {
			$surfaced = array_values(
				array_filter(
					array_map( 'strval', $gateway_ids ),
					static fn( string $gateway_id ): bool => ! isset( $hidden[ $gateway_id ] )
				)
			);

			if ( count( $surfaced ) >= 2 ) {
				$resolvable[ (string) $canonical_id ] = $surfaced;
			} elseif ( count( $gateway_ids ) >= 2 ) {
				// It was a real collision, but the representation hides enough of it that we can no
				// longer offer an individual choice. Report it instead of silently resolving it.
				$unresolvable[] = (string) $canonical_id;
			}
		}

		return array(
			'resolvable'   => $resolvable,
			'unresolvable' => $unresolvable,
		);
	}

	/**
	 * The gateway ids the representation does not surface as individual, manageable methods.
	 *
	 * @param array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}> $grouped_providers The grouped-providers representation.
	 *
	 * @return array<string, true> Set of hidden gateway ids.
	 */
	private function hidden_gateway_ids( array $grouped_providers ): array {
		$hidden = array();

		foreach ( $grouped_providers as $parent_gateway_id => $group ) {
			// The aggregate parent of an Optimized-Checkout group stands in for the whole provider, not
			// for one of its methods, so it is not an individually resolvable implementation either.
			if ( ! empty( $group['optimizedCheckout'] ) ) {
				$hidden[ (string) $parent_gateway_id ] = true;
			}

			// A group that does not show its children hides each of them.
			if ( empty( $group['showChildren'] ) ) {
				foreach ( (array) ( $group['childGatewayIds'] ?? array() ) as $child_gateway_id ) {
					$hidden[ (string) $child_gateway_id ] = true;
				}
			}
		}

		return $hidden;
	}
}
