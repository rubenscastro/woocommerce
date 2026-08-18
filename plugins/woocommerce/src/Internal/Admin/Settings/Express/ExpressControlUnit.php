<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

defined( 'ABSPATH' ) || exit;

/**
 * A single provider setting that can be turned off, and the express wallets it carries.
 *
 * Express duplicate resolution has to keep three things apart:
 *
 * 1. the **wallet** the merchant sees and chooses for (Apple Pay);
 * 2. the **provider** they choose (WooPayments);
 * 3. the **control unit** — this class — which is what actually gets toggled.
 *
 * The three are not interchangeable, because a provider's smallest togglable unit may carry more
 * than one wallet. WooPayments stores Apple Pay and Google Pay as two gateways with two option
 * arrays, but reads them with an OR in `is_payment_request_enabled()`, and that single flag gates
 * both client registrations — so disabling one wallet's gateway does not stop it being offered.
 * Its unit therefore carries both wallets. PayPal's wallets are genuinely independent and get one
 * unit each.
 *
 * That coupling is not derivable from gateway ids — from ids alone WooPayments looks independent —
 * which is why this metadata is contributed by provider-specific adapters rather than inferred.
 * See {@see ExpressControlUnitProviderInterface}.
 *
 * A unit is an immutable description of observed state, not a command: it says what exists and
 * what it carries, never how to change it.
 *
 * @internal
 *
 * @since 11.1.0
 */
class ExpressControlUnit {

	/**
	 * Canonical id of the Apple Pay wallet.
	 *
	 * @var string
	 */
	public const WALLET_APPLE_PAY = 'apple_pay';

	/**
	 * Canonical id of the Google Pay wallet.
	 *
	 * @var string
	 */
	public const WALLET_GOOGLE_PAY = 'google_pay';

	/**
	 * Unique id for the unit, namespaced by provider (e.g. `woopayments:wallets`).
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The plugin slug of the provider that owns the unit.
	 *
	 * @var string
	 */
	private string $provider_slug;

	/**
	 * Human-readable provider brand label.
	 *
	 * @var string
	 */
	private string $provider_label;

	/**
	 * The enabled gateway ids through which this unit surfaces.
	 *
	 * Used to join a unit to the detector's gateway-id based output. A unit may list several ids —
	 * including a provider's master gateway when express is surfaced through it — and all of them
	 * resolve back to this one unit.
	 *
	 * @var string[]
	 */
	private array $gateway_ids;

	/**
	 * The canonical wallet ids this unit provides while it is enabled.
	 *
	 * @var string[]
	 */
	private array $wallets;

	/**
	 * Whether the unit is currently enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Whether the unit can be turned off individually.
	 *
	 * @var bool
	 */
	private bool $can_disable;

	/**
	 * Gateway ids that must stay enabled for this unit to work at all.
	 *
	 * Express methods are frequently not standalone: a provider may serve them off the back of its
	 * card method, so turning that card off takes the wallets with it. Stripe enforces this at its
	 * API — it rejects a configuration that disables Card while its wallets are on — while Square
	 * simply stops registering them. Either way the merchant loses methods they never chose to lose,
	 * so the prerequisite has to be visible before they commit rather than discovered afterwards.
	 *
	 * @var string[]
	 */
	private array $requires;

	/**
	 * Constructor.
	 *
	 * @param string   $id             Unique, provider-namespaced unit id.
	 * @param string   $provider_slug  Plugin slug of the owning provider.
	 * @param string   $provider_label Provider brand label.
	 * @param string[] $gateway_ids    Gateway ids through which the unit surfaces.
	 * @param string[] $wallets        Canonical wallet ids the unit provides while enabled.
	 * @param bool     $enabled        Whether the unit is currently enabled.
	 * @param bool     $can_disable    Whether the unit can be turned off individually.
	 * @param string[] $requires       Gateway ids that must stay enabled for this unit to work.
	 */
	public function __construct(
		string $id,
		string $provider_slug,
		string $provider_label,
		array $gateway_ids,
		array $wallets,
		bool $enabled,
		bool $can_disable = true,
		array $requires = array()
	) {
		$this->id             = $id;
		$this->provider_slug  = $provider_slug;
		$this->provider_label = $provider_label;
		$this->gateway_ids    = array_values( array_unique( array_map( 'strval', $gateway_ids ) ) );
		$this->wallets        = array_values( array_unique( array_map( 'strval', $wallets ) ) );
		$this->enabled        = $enabled;
		$this->can_disable    = $can_disable;
		$this->requires       = array_values( array_unique( array_map( 'strval', $requires ) ) );
	}

	/**
	 * The unit id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * The owning provider's plugin slug.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return $this->provider_slug;
	}

	/**
	 * The owning provider's brand label.
	 *
	 * @return string
	 */
	public function get_provider_label(): string {
		return $this->provider_label;
	}

	/**
	 * The gateway ids through which the unit surfaces.
	 *
	 * @return string[]
	 */
	public function get_gateway_ids(): array {
		return $this->gateway_ids;
	}

	/**
	 * The canonical wallet ids the unit provides while enabled.
	 *
	 * @return string[]
	 */
	public function get_wallets(): array {
		return $this->wallets;
	}

	/**
	 * Whether the unit is currently enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Whether the unit can be turned off individually.
	 *
	 * @return bool
	 */
	public function can_disable(): bool {
		return $this->can_disable;
	}

	/**
	 * The gateway ids that must stay enabled for this unit to work.
	 *
	 * @return string[]
	 */
	public function get_requires(): array {
		return $this->requires;
	}

	/**
	 * Whether disabling the given gateway ids would take this unit down with them.
	 *
	 * @param string[] $disabled_gateway_ids The gateway ids being turned off.
	 *
	 * @return bool
	 */
	public function is_broken_by( array $disabled_gateway_ids ): bool {
		return ! empty( array_intersect( $this->requires, $disabled_gateway_ids ) );
	}

	/**
	 * Whether the unit carries the given canonical wallet id.
	 *
	 * @param string $wallet_id The canonical wallet id.
	 *
	 * @return bool
	 */
	public function provides_wallet( string $wallet_id ): bool {
		return in_array( $wallet_id, $this->wallets, true );
	}

	/**
	 * Whether the unit is valid enough to be used.
	 *
	 * A unit with no id, no gateway ids, or no wallets cannot be joined to anything, so it is
	 * dropped rather than half-applied.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return '' !== $this->id && ! empty( $this->gateway_ids ) && ! empty( $this->wallets );
	}
}
