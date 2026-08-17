<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use Throwable;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic duplicate payment-method detector for the "Payment methods" settings section.
 *
 * Detects when the same canonical payment method (Card, Klarna, iDEAL, Apple Pay / Google Pay…) is
 * enabled through more than one payment integration at the same time. It is a generalisation of the
 * algorithm WooPayments already ships in `WCPay\Duplicates_Detection_Service`, with the
 * WooPayments-specific `keep_gateways_enabled_in_woopayments()` step deliberately left out: a
 * collision is reported regardless of which providers are involved.
 *
 * The knowledge of *which* canonical methods exist and *which* gateway ids belong to them is not
 * owned here. Core seeds only the two universal cases the WooPayments detector hardcodes — the
 * `card` keyword set and the combined `apple_pay_google_pay` wallet keyword set — verbatim.
 * Everything else (Klarna, iDEAL, Afterpay…) is contributed by extensions through two filters, so
 * an extension such as WooPayments remains the single source of truth for its own metadata and core
 * takes no dependency on it.
 *
 * Discovery (which methods the page lists) is a separate concern handled elsewhere; this service
 * only produces the duplicate metadata that annotates methods already discovered.
 *
 * @internal
 */
class PaymentMethodDuplicatesDetector {

	/**
	 * Detect duplicate payment methods across the enabled payment gateways.
	 *
	 * The result separates regular payment methods from express (wallet) methods, because the page
	 * represents them differently and express methods are not first-class rows yet.
	 *
	 * Each value is the list of enabled gateway ids that resolve to the canonical method, and only
	 * canonical methods with two or more implementations are returned.
	 *
	 * @return array{payment_methods: array<string, string[]>, express: array<string, string[]>}
	 */
	public function detect(): array {
		$empty = array(
			'payment_methods' => array(),
			'express'         => array(),
		);

		try {
			$enabled_gateways = $this->get_enabled_gateways();

			if ( empty( $enabled_gateways ) ) {
				return $empty;
			}

			$definitions = $this->get_definitions();

			$groups = $this->match_by_keywords( $enabled_gateways, $definitions );
			$groups = $this->merge_gateway_hints( $groups, $enabled_gateways, $definitions );
			$groups = $this->keep_duplicates_only( $groups );

			return $this->split_by_kind( $groups, $definitions );
		} catch ( Throwable $e ) {
			// Detection is advisory metadata; never let a misbehaving gateway or extension take down
			// the settings page. Mirrors the fail-silent behaviour of the WooPayments detector.
			return $empty;
		}
	}

	/**
	 * The canonical payment-method definitions to match gateways against.
	 *
	 * Core contributes a baseline for the two special cases the WooPayments detector hardcodes:
	 * cards and the combined Apple Pay / Google Pay wallet bucket. The keyword sets are a verbatim
	 * lift of that detector's `search_for_cc()` and `search_for_payment_request_buttons()` keywords —
	 * this is not a new or broader canonicalisation. Extensions add the remaining methods.
	 *
	 * @return array<string, array{keywords: string[], express: bool}> Keyed by canonical method id.
	 */
	private function get_definitions(): array {
		$baseline = array(
			'card'                 => array(
				'keywords' => array( 'credit_card', 'creditcard', 'cc', 'card' ),
				'express'  => false,
			),
			'apple_pay_google_pay' => array(
				'keywords' => array( 'apple_pay', 'applepay', 'google_pay', 'googlepay' ),
				'express'  => true,
			),
		);

		/**
		 * Filters the canonical payment-method definitions used to detect duplicate payment methods.
		 *
		 * Each entry is keyed by a canonical method id and carries the gateway-id keywords that
		 * identify it and whether it is an express (wallet) method. Extensions contribute their own
		 * methods here — for example WooPayments maps its payment-method definition registry into
		 * this shape — so core does not need to know about them or depend on the extension.
		 *
		 * @param array<string, array{keywords: string[], express: bool}> $definitions Canonical
		 *        definitions keyed by canonical method id.
		 *
		 * @since 11.1.0
		 */
		$definitions = apply_filters( 'woocommerce_payment_method_duplicate_definitions', $baseline );

		return $this->normalize_definitions( $definitions );
	}

	/**
	 * Coerce filtered definitions into the expected shape, dropping anything malformed.
	 *
	 * Filter callbacks are third-party code, so nothing here trusts the value's structure.
	 *
	 * @param mixed $definitions The (possibly filtered) definitions value.
	 *
	 * @return array<string, array{keywords: string[], express: bool}>
	 */
	private function normalize_definitions( $definitions ): array {
		if ( ! is_array( $definitions ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $definitions as $canonical_id => $definition ) {
			if ( '' === (string) $canonical_id || ! is_array( $definition ) ) {
				continue;
			}

			$keywords = array();

			foreach ( (array) ( $definition['keywords'] ?? array() ) as $keyword ) {
				$keyword = strtolower( trim( (string) $keyword ) );

				if ( '' !== $keyword ) {
					$keywords[] = $keyword;
				}
			}

			$normalized[ (string) $canonical_id ] = array(
				'keywords' => array_values( array_unique( $keywords ) ),
				'express'  => ! empty( $definition['express'] ),
			);
		}

		return $normalized;
	}

	/**
	 * Bucket enabled gateways under a canonical method by matching their ids against keywords.
	 *
	 * A keyword matches only when it stands as a whole `_`/`-` delimited token in the gateway id, not as
	 * a substring inside an unrelated word. This is stricter than the raw substring test the WooPayments
	 * detector uses, and deliberately so: the short `cc` card keyword otherwise matches the "cc" inside
	 * `stripe_us_bank_account`, pulling ACH Direct Debit into the Card group. Gateway ids are already
	 * segmented by `_`/`-` (`stripe_ideal`, `ppcp-ideal`, `woocommerce_payments_afterpay_clearpay`), so
	 * token matching still catches every real method while dropping those false positives.
	 *
	 * The first keyword a gateway id matches wins, so each gateway lands in at most one keyword bucket.
	 *
	 * @param array<string, WC_Payment_Gateway>                       $enabled_gateways Enabled gateways keyed by id.
	 * @param array<string, array{keywords: string[], express: bool}> $definitions      Canonical definitions.
	 *
	 * @return array<string, string[]> Gateway ids keyed by canonical method id.
	 */
	private function match_by_keywords( array $enabled_gateways, array $definitions ): array {
		$keyword_map = array();

		foreach ( $definitions as $canonical_id => $definition ) {
			foreach ( $definition['keywords'] as $keyword ) {
				// First definition to claim a keyword keeps it.
				if ( ! isset( $keyword_map[ $keyword ] ) ) {
					$keyword_map[ $keyword ] = $canonical_id;
				}
			}
		}

		$groups = array();

		foreach ( $enabled_gateways as $gateway_id => $gateway ) {
			foreach ( $keyword_map as $keyword => $canonical_id ) {
				if ( $this->keyword_matches_gateway_id( (string) $gateway_id, $keyword ) ) {
					$groups[ $canonical_id ][] = (string) $gateway_id;
					break;
				}
			}
		}

		return $groups;
	}

	/**
	 * Whether a keyword matches a gateway id as a whole `_`/`-` delimited token.
	 *
	 * The keyword must sit at the start/end of the id or be flanked by a `_`/`-` separator, so `cc`
	 * matches a `..._cc_...` segment but never the letters inside `account`. The keyword may itself
	 * contain separators (e.g. `apple_pay`), which are treated literally.
	 *
	 * @param string $gateway_id The gateway id.
	 * @param string $keyword    The keyword to match.
	 *
	 * @return bool
	 */
	private function keyword_matches_gateway_id( string $gateway_id, string $keyword ): bool {
		if ( '' === $keyword ) {
			return false;
		}

		return 1 === preg_match( '/(?:^|[_-])' . preg_quote( $keyword, '/' ) . '(?:$|[_-])/', $gateway_id );
	}

	/**
	 * Merge in gateway matches that keyword matching cannot see.
	 *
	 * Some gateways belong to a canonical method even though their id carries no matching keyword —
	 * a card gateway whose id is just the provider name, or a wallet enabled through a gateway option
	 * rather than a dedicated gateway id. Only the extension owning the gateway can decide those, so
	 * it contributes them here as already-resolved `canonical_id => gateway_ids` hints.
	 *
	 * @param array<string, string[]>                                 $groups           Groups from keyword matching.
	 * @param array<string, WC_Payment_Gateway>                       $enabled_gateways Enabled gateways keyed by id.
	 * @param array<string, array{keywords: string[], express: bool}> $definitions      Canonical definitions.
	 *
	 * @return array<string, string[]>
	 */
	private function merge_gateway_hints( array $groups, array $enabled_gateways, array $definitions ): array {
		/**
		 * Filters extra, probe-based gateway matches for duplicate payment-method detection.
		 *
		 * Keyword matching cannot detect a gateway that belongs to a canonical method without naming
		 * it in its id — a provider whose card gateway id is just the provider name, or a wallet
		 * toggled on through a gateway option. Extensions contribute those already-resolved matches
		 * here, keyed by canonical method id. Only enabled gateways are passed in, so callbacks can
		 * probe live gateway state safely.
		 *
		 * @param array<string, string[]>           $hints            Gateway ids keyed by canonical
		 *        method id.
		 * @param array<string, WC_Payment_Gateway> $enabled_gateways Enabled gateways keyed by id.
		 *
		 * @since 11.1.0
		 */
		$hints = apply_filters( 'woocommerce_payment_method_duplicate_gateway_hints', array(), $enabled_gateways );

		if ( ! is_array( $hints ) ) {
			return $groups;
		}

		$enabled_ids = array_map( 'strval', array_keys( $enabled_gateways ) );

		foreach ( $hints as $canonical_id => $gateway_ids ) {
			if ( '' === (string) $canonical_id || ! isset( $definitions[ (string) $canonical_id ] ) || ! is_array( $gateway_ids ) ) {
				continue;
			}

			foreach ( $gateway_ids as $gateway_id ) {
				$gateway_id = (string) $gateway_id;

				// Only trust hints for gateways that are actually enabled.
				if ( in_array( $gateway_id, $enabled_ids, true ) ) {
					$groups[ (string) $canonical_id ][] = $gateway_id;
				}
			}
		}

		return $groups;
	}

	/**
	 * Drop canonical methods that resolve to fewer than two implementations.
	 *
	 * Gateway ids are de-duplicated first, so the same gateway matched twice does not read as a
	 * collision.
	 *
	 * @param array<string, string[]> $groups Gateway ids keyed by canonical method id.
	 *
	 * @return array<string, string[]>
	 */
	private function keep_duplicates_only( array $groups ): array {
		$duplicates = array();

		foreach ( $groups as $canonical_id => $gateway_ids ) {
			$gateway_ids = array_values( array_unique( $gateway_ids ) );

			if ( count( $gateway_ids ) >= 2 ) {
				$duplicates[ $canonical_id ] = $gateway_ids;
			}
		}

		return $duplicates;
	}

	/**
	 * Separate regular from express canonical methods using each definition's express flag.
	 *
	 * @param array<string, string[]>                                 $groups      Duplicate groups.
	 * @param array<string, array{keywords: string[], express: bool}> $definitions Canonical definitions.
	 *
	 * @return array{payment_methods: array<string, string[]>, express: array<string, string[]>}
	 */
	private function split_by_kind( array $groups, array $definitions ): array {
		$result = array(
			'payment_methods' => array(),
			'express'         => array(),
		);

		foreach ( $groups as $canonical_id => $gateway_ids ) {
			$bucket = ! empty( $definitions[ $canonical_id ]['express'] ) ? 'express' : 'payment_methods';

			$result[ $bucket ][ $canonical_id ] = $gateway_ids;
		}

		return $result;
	}

	/**
	 * The enabled payment gateways, keyed by id.
	 *
	 * Reads the same canonical accessor the rest of the settings section uses and filters by the
	 * gateway's own enabled flag. Returns an empty array in contexts where the gateways component is
	 * not initialised (CLI, some REST/cron paths).
	 *
	 * @return array<string, WC_Payment_Gateway>
	 */
	private function get_enabled_gateways(): array {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return array();
		}

		$enabled = array();

		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			if ( $gateway instanceof WC_Payment_Gateway && filter_var( $gateway->enabled, FILTER_VALIDATE_BOOLEAN ) ) {
				$enabled[ (string) $gateway_id ] = $gateway;
			}
		}

		return $enabled;
	}
}
