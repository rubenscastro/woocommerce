<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical checkout payment-method ordering service.
 *
 * This class owns the persisted, canonical order of the individual checkout payment methods
 * chosen by the merchant on the Payments > Payment methods settings page.
 *
 * The stored identity of each method is the Checkout Block registry `name`, which for every
 * method that is actually selectable at checkout equals its available WC payment gateway ID
 * (so it doubles as a valid Classic checkout gateway ID). This is deliberately kept separate
 * from provider/presentation ordering (see PaymentsProviders), which carries UI-only pseudo
 * entries such as suggestions and the offline methods group.
 *
 * Existence of the option is the explicit boundary:
 * - option absent => legacy/default ordering fallback is in effect;
 * - option present => canonical payment-method ordering is active (and is always non-empty).
 *
 * @internal
 *
 * @since 11.1.0
 */
class PaymentMethodsOrder {

	/**
	 * The option name holding the canonical, ordered list of checkout payment-method IDs.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'woocommerce_payment_method_order';

	/**
	 * Whether a canonical payment-method order has been persisted.
	 *
	 * Uses a sentinel default so an absent option is distinguished from any stored value.
	 * Never branch fallback logic on the truthiness of the stored contents: an empty order
	 * can never be persisted (it is rejected at write time), so "exists" always implies a
	 * non-empty list.
	 *
	 * @return bool True if the canonical order option exists, false otherwise.
	 */
	public function exists(): bool {
		return false !== get_option( self::OPTION_NAME, false );
	}

	/**
	 * Get the raw, stored canonical order.
	 *
	 * This is the source of truth for restoring positions and may contain entries for methods
	 * that are currently disabled or no longer installed (stale). It is intentionally not
	 * filtered against the live registry.
	 *
	 * @return string[] The ordered list of stored payment-method IDs (may be empty if absent).
	 */
	public function get_raw(): array {
		$stored = get_option( self::OPTION_NAME, false );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return $this->normalize( $stored );
	}

	/**
	 * Get the enabled-filtered order to expose to checkout.
	 *
	 * The stored order is filtered down to the currently enabled gateways, preserving the
	 * canonical order; any enabled gateway that is not yet part of the stored order is appended
	 * deterministically in WooCommerce gateway order. Filtering here does not lose the ability to
	 * restore a disabled method's position because that lives in the stored option, not here.
	 *
	 * @return string[] The ordered list of enabled payment-method IDs for checkout consumption.
	 */
	public function get_for_checkout(): array {
		$enabled   = $this->get_enabled_gateway_ids();
		$remaining = array_fill_keys( $enabled, true );

		$result = array();
		foreach ( $this->get_raw() as $id ) {
			if ( isset( $remaining[ $id ] ) ) {
				$result[] = $id;
				unset( $remaining[ $id ] );
			}
		}

		// Append any enabled gateways not present in the stored order, in WC gateway order.
		foreach ( $enabled as $id ) {
			if ( isset( $remaining[ $id ] ) ) {
				$result[] = $id;
			}
		}

		return $result;
	}

	/**
	 * Persist a new payment-method order submitted from the admin UI.
	 *
	 * The submission is the authoritative order of the currently visible (enabled + known)
	 * methods. It is merged into the stored order so that entries which are not part of the
	 * submission (currently disabled or stale) keep a stable position for later restore.
	 *
	 * @param string[] $submitted The ordered list of payment-method IDs from the UI.
	 *
	 * @return bool True on success.
	 * @throws InvalidArgumentException If the submitted order is empty after normalization.
	 */
	public function save( array $submitted ): bool {
		$submitted = $this->normalize( $submitted );

		if ( empty( $submitted ) ) {
			throw new InvalidArgumentException( 'The payment method order must contain at least one valid payment method ID.' );
		}

		$merged = self::merge( $submitted, $this->get_raw() );

		// Note: update_option() returns false when the value is unchanged, which is not an error,
		// so we don't propagate its return value here.
		update_option( self::OPTION_NAME, $merged );

		return true;
	}

	/**
	 * Merge a submitted order into the existing stored order (previous-neighbour anchor).
	 *
	 * The submitted order is authoritative for every ID it contains (including brand-new ones).
	 * Each stored ID that is not part of the submission is woven back in right after its previous
	 * still-present neighbour, so a disabled or stale method keeps a stable, restorable position.
	 *
	 * @param string[] $submitted The authoritative order of currently-visible IDs.
	 * @param string[] $old       The previously stored order.
	 *
	 * @return string[] The merged order.
	 */
	public static function merge( array $submitted, array $old ): array {
		$result = array_values( $submitted );
		$seen   = array_fill_keys( $result, true );

		// Insertion cursor into $result; -1 means "insert at the front".
		$anchor = -1;
		foreach ( $old as $id ) {
			if ( isset( $seen[ $id ] ) ) {
				$anchor = array_search( $id, $result, true );
				continue;
			}

			$insert_at = $anchor + 1;
			array_splice( $result, $insert_at, 0, array( $id ) );
			$seen[ $id ] = true;
			$anchor      = $insert_at;
		}

		return $result;
	}

	/**
	 * Build a partial gateway order map for the Classic-checkout compatibility projection.
	 *
	 * Only IDs that correspond to currently registered payment gateways are included (stale IDs
	 * are dropped); each is assigned a sequential integer order following the canonical order.
	 * The resulting partial map is meant to be fed through the provider order-map writer, which
	 * repositions these gateways while preserving the relative order of everything else.
	 *
	 * @param string[] $ids The ordered list of payment-method IDs.
	 *
	 * @return array<string, int> Map of gateway ID to integer order.
	 */
	public function build_gateway_order_map( array $ids ): array {
		$registered = array_fill_keys( $this->get_registered_gateway_ids(), true );

		$map   = array();
		$order = 0;
		foreach ( $this->normalize( $ids ) as $id ) {
			if ( isset( $registered[ $id ] ) ) {
				$map[ $id ] = $order++;
			}
		}

		return $map;
	}

	/**
	 * Normalize a list of payment-method IDs to canonical, unique, non-empty string values.
	 *
	 * This is the single authority on the canonical identity of a stored payment-method ID: it
	 * applies the same sanitization to every code path (REST callers and direct PHP callers alike),
	 * so the persisted option never contains two subtly different forms of the same ID.
	 *
	 * @param array $ids The list of IDs to normalize.
	 *
	 * @return string[] The normalized list (first occurrence wins on duplicates).
	 */
	private function normalize( array $ids ): array {
		$normalized = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) && ! is_numeric( $id ) ) {
				continue;
			}

			$id = $this->sanitize_id( (string) $id );
			if ( '' === $id || in_array( $id, $normalized, true ) ) {
				continue;
			}

			$normalized[] = $id;
		}

		return $normalized;
	}

	/**
	 * Sanitize a single payment-method ID to its canonical form.
	 *
	 * Strips HTML tags, accents, percent-encoded characters, and HTML entities, then keeps only
	 * lowercase/uppercase ASCII letters, digits, underscores, and dashes.
	 *
	 * @param string $id The ID to sanitize.
	 *
	 * @return string The canonical ID (may be an empty string if nothing valid remains).
	 */
	private function sanitize_id( string $id ): string {
		$id = wp_strip_all_tags( $id );
		$id = remove_accents( $id );
		// Remove percent-encoded characters.
		$id = (string) preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '', $id );
		// Remove HTML entities.
		$id = (string) preg_replace( '/&.+?;/', '', $id );

		// Only lowercase and uppercase ASCII letters, digits, underscores, and dashes are allowed.
		return (string) preg_replace( '|[^a-z0-9_\-]|i', '', $id );
	}

	/**
	 * Get the IDs of the currently enabled payment gateways, in WooCommerce gateway order.
	 *
	 * @return string[] The enabled gateway IDs.
	 */
	private function get_enabled_gateway_ids(): array {
		$enabled = array();
		foreach ( $this->get_payment_gateways() as $id => $gateway ) {
			if ( filter_var( $gateway->enabled, FILTER_VALIDATE_BOOLEAN ) ) {
				$enabled[] = (string) $id;
			}
		}

		return $enabled;
	}

	/**
	 * Get the IDs of all registered payment gateways (enabled or not).
	 *
	 * @return string[] The registered gateway IDs.
	 */
	private function get_registered_gateway_ids(): array {
		return array_map( 'strval', array_keys( $this->get_payment_gateways() ) );
	}

	/**
	 * Get the registered payment gateways keyed by ID, guarding for non-standard request contexts.
	 *
	 * @return array<string, \WC_Payment_Gateway> The registered gateways.
	 */
	private function get_payment_gateways(): array {
		if ( ! function_exists( 'WC' ) || is_null( WC()->payment_gateways() ) ) {
			return array();
		}

		return WC()->payment_gateways()->payment_gateways();
	}
}
