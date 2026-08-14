<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use Throwable;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates resolution of duplicated *regular* payment methods.
 *
 * Given the merchant's choices — for each duplicated canonical method, the one implementation to keep —
 * this service disables every other participating implementation, leaving the kept one and all
 * unrelated methods/providers untouched.
 *
 * It orchestrates only. It never mutates gateway state itself: the *what is duplicated* question stays
 * with {@see PaymentMethodDuplicatesDetector}, the *what is individually resolvable* question stays with
 * the representation ({@see DuplicateResolutionCandidates} over {@see StripeOptimizedCheckoutAdapter}),
 * and the *how to disable one method* question stays with a resolver — an integration-owned one
 * contributed through the `woocommerce_payment_method_duplicate_resolvers` filter, or the core
 * {@see GenericPaymentMethodDuplicateResolver} when the target is positively a standalone gateway.
 *
 * Scope note: this spike resolves regular checkout methods only. Express (wallet) duplicates are never
 * accepted here; the mutation contract will be extended deliberately when express resolution is
 * designed against its real semantics.
 *
 * The client submits only *what to keep*; this service re-runs detection server-side and derives *what
 * to disable* from fresh state, so stale modal data can never disable an unrelated or already-resolved
 * method.
 *
 * @internal
 *
 * @since 11.1.0
 */
class PaymentMethodDuplicatesResolver {

	/**
	 * Resolve a batch of duplicate selections.
	 *
	 * @param array<string, string> $selections Map of canonical method id => the gateway id to keep enabled.
	 *
	 * @return array{success: bool, results: array<int, array{canonicalId: string, kept: string, disabled: array<int, array{gatewayId: string, status: string, message: string}>, error: string|null}>, duplicates: array{payment_methods: array<string, string[]>, express: array<string, string[]>}}
	 */
	public function resolve( array $selections ): array {
		$detected = $this->detect();

		$grouped    = $this->get_grouped_providers();
		$resolvable = ( new DuplicateResolutionCandidates() )->compute( $detected['payment_methods'], $grouped )['resolvable'];

		// The authoritative candidate model: which implementations can be disabled, and whether a
		// canonical has a single required keep. This is the same model handed to the frontend, so the
		// server, not the client, decides what is a valid selection.
		$candidates = $this->describe_candidates( $resolvable, $grouped );

		$extension_resolvers = $this->get_extension_resolvers();
		$generic             = new GenericPaymentMethodDuplicateResolver();

		$results = array();
		$success = true;

		foreach ( $selections as $canonical_id => $kept_gateway_id ) {
			$canonical_id    = (string) $canonical_id;
			$kept_gateway_id = (string) $kept_gateway_id;

			$error = $this->validate_selection( $canonical_id, $kept_gateway_id, $candidates );

			if ( null !== $error ) {
				$results[] = array(
					'canonicalId' => $canonical_id,
					'kept'        => $kept_gateway_id,
					'disabled'    => array(),
					'error'       => $error,
				);
				$success   = false;
				continue;
			}

			$group_ids = array_column( $candidates[ $canonical_id ]['implementations'], 'gatewayId' );
			$targets   = array_values( array_diff( $group_ids, array( $kept_gateway_id ) ) );
			$disabled  = array();

			foreach ( $targets as $target_gateway_id ) {
				$outcome    = $this->disable_one( $target_gateway_id, $extension_resolvers, $generic, $grouped );
				$disabled[] = $outcome;

				if ( 'disabled' !== $outcome['status'] ) {
					$success = false;
				}
			}

			$results[] = array(
				'canonicalId' => $canonical_id,
				'kept'        => $kept_gateway_id,
				'disabled'    => $disabled,
				'error'       => null,
			);
		}

		if ( empty( $selections ) ) {
			$success = false;
		}

		// Re-run detection so the response carries authoritative post-mutation state.
		return array(
			'success'    => $success,
			'results'    => $results,
			'duplicates' => $this->detect(),
		);
	}

	/**
	 * Describe the resolvable regular duplicates: per-implementation disable capability and the single
	 * required keep, if any.
	 *
	 * The rule mirrors what the modal must present:
	 * - no non-disableable implementation → merchant chooses freely (`requiredKeepGatewayId` is null);
	 * - exactly one non-disableable implementation → it is the only valid keep (`requiredKeepGatewayId`);
	 * - more than one non-disableable implementation → keeping one still leaves another that cannot be
	 *   turned off, so the canonical is not safely resolvable here and is omitted entirely.
	 *
	 * @param array<string, string[]>                                                                                           $resolvable        The fresh resolvable regular groups.
	 * @param array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}> $grouped_providers The grouped-providers representation.
	 *
	 * @return array<string, array{implementations: array<int, array{gatewayId: string, canDisable: bool}>, requiredKeepGatewayId: string|null}>
	 */
	public function describe_candidates( array $resolvable, array $grouped_providers ): array {
		$extension_resolvers = $this->get_extension_resolvers();

		$candidates = array();

		foreach ( $resolvable as $canonical_id => $gateway_ids ) {
			$implementations = array();
			$non_disableable = array();

			foreach ( $gateway_ids as $gateway_id ) {
				$gateway_id = (string) $gateway_id;
				$can        = $this->can_disable_target( $gateway_id, $extension_resolvers, $grouped_providers );

				$implementations[] = array(
					'gatewayId'  => $gateway_id,
					'canDisable' => $can,
				);

				if ( ! $can ) {
					$non_disableable[] = $gateway_id;
				}
			}

			if ( count( $non_disableable ) > 1 ) {
				continue;
			}

			$candidates[ (string) $canonical_id ] = array(
				'implementations'       => $implementations,
				'requiredKeepGatewayId' => 1 === count( $non_disableable ) ? $non_disableable[0] : null,
			);
		}

		return $candidates;
	}

	/**
	 * Whether the given implementation can be disabled, using the resolver that owns it.
	 *
	 * @param string                                                                                                            $gateway_id          The gateway id.
	 * @param PaymentMethodDuplicateResolverInterface[]                                                                         $extension_resolvers The registered integration resolvers.
	 * @param array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}> $grouped_providers   The grouped-providers representation.
	 *
	 * @return bool
	 */
	private function can_disable_target( string $gateway_id, array $extension_resolvers, array $grouped_providers ): bool {
		foreach ( $extension_resolvers as $resolver ) {
			if ( $resolver->supports( $gateway_id ) ) {
				return $resolver->can_disable( $gateway_id );
			}
		}

		// No integration owns it: the generic path can disable it only when it is a positively-standalone
		// gateway; otherwise it is unsupported (and therefore not disableable here).
		return $this->is_generic_eligible( $gateway_id, $grouped_providers );
	}

	/**
	 * Fresh duplicate detection. Isolated so tests can drive it deterministically.
	 *
	 * @return array{payment_methods: array<string, string[]>, express: array<string, string[]>}
	 */
	protected function detect(): array {
		return ( new PaymentMethodDuplicatesDetector() )->detect();
	}

	/**
	 * The grouped-providers representation. Isolated so tests can drive the representation rule
	 * (e.g. Optimized Checkout hiding) without depending on a live Stripe gateway.
	 *
	 * @return array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}>
	 */
	protected function get_grouped_providers(): array {
		return ( new StripeOptimizedCheckoutAdapter() )->get_grouped_providers();
	}

	/**
	 * Validate one selection against fresh server state.
	 *
	 * This service resolves regular checkout duplicates only. Express duplicates are simply not part of
	 * the regular resolvable set, so they are never consulted or validated here — an express canonical
	 * id can only ever fall through as `not_resolvable`, and no express state is mutated.
	 *
	 * @param string                                                                                                                            $canonical_id    The canonical method id.
	 * @param string                                                                                                                            $kept_gateway_id The gateway id the merchant chose to keep.
	 * @param array<string, array{implementations: array<int, array{gatewayId: string, canDisable: bool}>, requiredKeepGatewayId: string|null}> $candidates The fresh candidate model.
	 *
	 * @return string|null An error code, or null when the selection is valid.
	 */
	private function validate_selection( string $canonical_id, string $kept_gateway_id, array $candidates ): ?string {
		// Not a resolvable regular duplicate: unknown, stale, already resolved, express (a separate
		// bucket), hidden by the representation (Optimized-Checkout-only collision), or unresolvable
		// because more than one implementation cannot be disabled.
		if ( ! isset( $candidates[ $canonical_id ] ) ) {
			return 'not_resolvable';
		}

		$group_ids = array_column( $candidates[ $canonical_id ]['implementations'], 'gatewayId' );

		// The kept implementation must belong to the fresh group.
		if ( ! in_array( $kept_gateway_id, $group_ids, true ) ) {
			return 'invalid_selection';
		}

		// When one implementation cannot be disabled it is the only valid keep, regardless of what the
		// client submitted — stale UI state cannot force keeping the disableable one instead.
		$required_keep = $candidates[ $canonical_id ]['requiredKeepGatewayId'];
		if ( null !== $required_keep && $kept_gateway_id !== $required_keep ) {
			return 'required_keep_mismatch';
		}

		return null;
	}

	/**
	 * Disable a single implementation through the appropriate resolver.
	 *
	 * @param string                                                                                                            $gateway_id          The gateway id to disable.
	 * @param PaymentMethodDuplicateResolverInterface[]                                                                         $extension_resolvers The registered integration resolvers.
	 * @param GenericPaymentMethodDuplicateResolver                                                                             $generic             The core generic resolver.
	 * @param array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}> $grouped_providers   The grouped-providers representation.
	 *
	 * @return array{gatewayId: string, status: string, message: string}
	 */
	private function disable_one( string $gateway_id, array $extension_resolvers, GenericPaymentMethodDuplicateResolver $generic, array $grouped_providers ): array {
		try {
			foreach ( $extension_resolvers as $resolver ) {
				if ( $resolver->supports( $gateway_id ) ) {
					// A method the owning integration keeps mandatory (e.g. WooPayments Card) must never be
					// disabled — guard here so this holds even if validation upstream is bypassed.
					if ( ! $resolver->can_disable( $gateway_id ) ) {
						return array(
							'gatewayId' => $gateway_id,
							'status'    => 'unsupported',
							'message'   => 'This payment method cannot be disabled individually.',
						);
					}

					return $this->format_outcome( $gateway_id, $resolver->disable( $gateway_id ) );
				}
			}

			// No integration owns this id. Use the generic path only when we can positively establish it
			// is a standalone gateway; otherwise fail safe. Absence of a resolver never authorises a
			// blind `enabled=no` write.
			if ( $this->is_generic_eligible( $gateway_id, $grouped_providers ) ) {
				return $this->format_outcome( $gateway_id, $generic->disable( $gateway_id ) );
			}

			return array(
				'gatewayId' => $gateway_id,
				'status'    => 'unsupported',
				'message'   => 'No integration resolver is registered and the gateway could not be positively identified as a single standalone payment method.',
			);
		} catch ( Throwable $e ) {
			return array(
				'gatewayId' => $gateway_id,
				'status'    => 'failed',
				'message'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Whether the generic core resolver may safely disable this gateway.
	 *
	 * Positive, structural test only — no gateway-id heuristics, no provider-name allowlists:
	 * 1. the id is a registered standalone `WC_Payment_Gateway`; and
	 * 2. the representation surfaces it as its own method — it is not the parent of, nor a child in, any
	 *    grouped/aggregated provider.
	 *
	 * When this cannot be established the caller returns `unsupported`. Note this test cannot disprove a
	 * single gateway id that hides several methods; that residual gap is a documented architectural
	 * finding, intentionally not worked around here.
	 *
	 * @param string                                                                                                            $gateway_id        The gateway id.
	 * @param array<string, array{childGatewayIds: string[], showChildren: bool, optimizedCheckout: bool, settingsUrl: string}> $grouped_providers The grouped-providers representation.
	 *
	 * @return bool
	 */
	private function is_generic_eligible( string $gateway_id, array $grouped_providers ): bool {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return false;
		}

		$gateway = WC()->payment_gateways()->payment_gateways()[ $gateway_id ] ?? null;

		if ( ! $gateway instanceof WC_Payment_Gateway ) {
			return false;
		}

		// Parent of a group.
		if ( isset( $grouped_providers[ $gateway_id ] ) ) {
			return false;
		}

		// Child of a group.
		foreach ( $grouped_providers as $group ) {
			if ( in_array( $gateway_id, array_map( 'strval', (array) ( $group['childGatewayIds'] ?? array() ) ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Coerce a resolver's return value into the outcome shape, distrusting third-party structure.
	 *
	 * @param string $gateway_id The gateway id.
	 * @param mixed  $result     The resolver's returned value.
	 *
	 * @return array{gatewayId: string, status: string, message: string}
	 */
	private function format_outcome( string $gateway_id, $result ): array {
		$status  = is_array( $result ) && isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$message = is_array( $result ) && isset( $result['message'] ) ? (string) $result['message'] : '';

		// A resolver may only report success or failure of the mutation; other statuses are core's.
		if ( 'disabled' !== $status ) {
			$status = 'failed';
		}

		return array(
			'gatewayId' => $gateway_id,
			'status'    => $status,
			'message'   => $message,
		);
	}

	/**
	 * The integration-owned resolvers, filtered to valid instances.
	 *
	 * @return PaymentMethodDuplicateResolverInterface[]
	 */
	private function get_extension_resolvers(): array {
		/**
		 * Filters the resolvers that can disable an individual duplicated payment method.
		 *
		 * Each entry must implement {@see PaymentMethodDuplicateResolverInterface}. An integration
		 * registers one so it can disable a single method the way its own storage requires (WooPayments'
		 * split gateways, Stripe's aggregated enabled-method list) rather than core writing options it
		 * does not understand. This is intentionally separate from the detection filters
		 * (`woocommerce_payment_method_duplicate_definitions`,
		 * `woocommerce_payment_method_duplicate_gateway_hints`): contributing duplicate metadata and
		 * owning the mutation are distinct capabilities.
		 *
		 * @param array $resolvers Registered resolver instances; each must implement
		 *                         {@see PaymentMethodDuplicateResolverInterface}.
		 *
		 * @since 11.1.0
		 */
		$resolvers = apply_filters( 'woocommerce_payment_method_duplicate_resolvers', array() );

		if ( ! is_array( $resolvers ) ) {
			return array();
		}

		// Hook output is untrusted regardless of the documented type, so every entry is validated here
		// before it is treated as a resolver.
		return array_values(
			array_filter(
				$resolvers,
				static fn( $resolver ): bool => $resolver instanceof PaymentMethodDuplicateResolverInterface
			)
		);
	}
}
