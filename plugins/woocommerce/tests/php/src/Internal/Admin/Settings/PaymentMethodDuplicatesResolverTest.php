<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodDuplicateResolverInterface;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentMethodDuplicatesResolver;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentMethodDuplicatesResolver class.
 */
class PaymentMethodDuplicatesResolverTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_payment_gateways' );
		remove_all_filters( 'woocommerce_payment_method_duplicate_definitions' );
		remove_all_filters( 'woocommerce_payment_method_duplicate_resolvers' );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		parent::tearDown();
	}

	/**
	 * @testdox Should keep the chosen implementation enabled and disable the other via the generic path.
	 */
	public function test_keeps_selected_and_disables_other_implementation(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
			)
		);

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'klarna' => 'foo_klarna' ) );

		$this->assertTrue( $report['success'], 'The resolution should succeed.' );
		$this->assertSame( 'yes', $this->gateway( 'foo_klarna' )->enabled, 'The kept implementation stays enabled.' );
		$this->assertSame( 'no', $this->gateway( 'bar_klarna' )->enabled, 'The non-selected implementation is disabled.' );
		$this->assertArrayNotHasKey( 'klarna', $report['duplicates']['payment_methods'], 'Fresh detection no longer reports the collision.' );
	}

	/**
	 * @testdox Should disable the opposite implementation when the other provider is kept.
	 */
	public function test_keeps_the_other_selection_symmetrically(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
			)
		);

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'klarna' => 'bar_klarna' ) );

		$this->assertTrue( $report['success'], 'The resolution should succeed.' );
		$this->assertSame( 'yes', $this->gateway( 'bar_klarna' )->enabled, 'The kept implementation stays enabled.' );
		$this->assertSame( 'no', $this->gateway( 'foo_klarna' )->enabled, 'The non-selected implementation is disabled.' );
	}

	/**
	 * @testdox Should leave unrelated methods of the same and other providers untouched.
	 */
	public function test_leaves_unrelated_methods_untouched(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
				'ideal'  => array(
					'keywords' => array( 'ideal' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
				'bar_ideal'  => 'yes',
				'baz_card'   => 'yes',
			)
		);

		( new PaymentMethodDuplicatesResolver() )->resolve( array( 'klarna' => 'foo_klarna' ) );

		$this->assertSame( 'yes', $this->gateway( 'bar_ideal' )->enabled, 'An unrelated method of the same provider stays enabled.' );
		$this->assertSame( 'yes', $this->gateway( 'baz_card' )->enabled, 'An unrelated provider is unchanged.' );
	}

	/**
	 * @testdox Should resolve several duplicate groups in a single request.
	 */
	public function test_resolves_multiple_groups_in_one_request(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
				'ideal'  => array(
					'keywords' => array( 'ideal' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
				'a_ideal'    => 'yes',
				'b_ideal'    => 'yes',
			)
		);

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve(
			array(
				'klarna' => 'foo_klarna',
				'ideal'  => 'b_ideal',
			)
		);

		$this->assertTrue( $report['success'], 'Both groups should resolve.' );
		$this->assertSame( 'no', $this->gateway( 'bar_klarna' )->enabled, 'The non-kept klarna is disabled.' );
		$this->assertSame( 'no', $this->gateway( 'a_ideal' )->enabled, 'The non-kept ideal is disabled.' );
		$this->assertSame( 'yes', $this->gateway( 'foo_klarna' )->enabled, 'The kept klarna stays enabled.' );
		$this->assertSame( 'yes', $this->gateway( 'b_ideal' )->enabled, 'The kept ideal stays enabled.' );
	}

	/**
	 * @testdox Should reject an unknown canonical method and mutate nothing.
	 */
	public function test_rejects_unknown_canonical_id(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
			)
		);

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'not_a_method' => 'foo_klarna' ) );

		$this->assertFalse( $report['success'], 'An unknown canonical must not report success.' );
		$this->assertSame( 'not_resolvable', $report['results'][0]['error'], 'It is reported as not resolvable.' );
		$this->assertSame( 'yes', $this->gateway( 'foo_klarna' )->enabled, 'Nothing is disabled.' );
		$this->assertSame( 'yes', $this->gateway( 'bar_klarna' )->enabled, 'Nothing is disabled.' );
	}

	/**
	 * @testdox Should reject a kept implementation that does not belong to the duplicate group.
	 */
	public function test_rejects_selection_not_in_group(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
			)
		);

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'klarna' => 'some_other_gateway' ) );

		$this->assertFalse( $report['success'], 'An invalid selection must not report success.' );
		$this->assertSame( 'invalid_selection', $report['results'][0]['error'], 'It is reported as an invalid selection.' );
		$this->assertSame( 'yes', $this->gateway( 'bar_klarna' )->enabled, 'Nothing is disabled.' );
	}

	/**
	 * @testdox Should treat an already-resolved method as no longer a duplicate.
	 */
	public function test_stale_already_resolved_method_is_not_resolvable(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		// Only one klarna implementation is enabled, so it is not (or no longer) a duplicate.
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'no',
			)
		);

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'klarna' => 'foo_klarna' ) );

		$this->assertFalse( $report['success'], 'A stale selection must not report success.' );
		$this->assertSame( 'not_resolvable', $report['results'][0]['error'], 'It is reported as not resolvable.' );
	}

	/**
	 * @testdox Should disable through an integration-owned resolver when one claims the gateway.
	 */
	public function test_uses_extension_resolver_when_registered(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
			)
		);
		$resolver = $this->register_fake_resolver( array( 'bar_klarna' ) );

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'klarna' => 'foo_klarna' ) );

		$this->assertTrue( $report['success'], 'The extension resolver should succeed.' );
		$this->assertSame( array( 'bar_klarna' ), $resolver->disabled_ids, 'The extension resolver was asked to disable exactly the non-kept implementation.' );
		$this->assertSame( 'no', $this->gateway( 'bar_klarna' )->enabled, 'The extension resolver disabled it.' );
	}

	/**
	 * @testdox Should report a partial failure without disabling the kept implementation.
	 */
	public function test_partial_failure_is_reported(): void {
		$this->contribute_definitions(
			array(
				'klarna' => array(
					'keywords' => array( 'klarna' ),
					'express'  => false,
				),
			)
		);
		$this->register_fake_gateways(
			array(
				'foo_klarna' => 'yes',
				'bar_klarna' => 'yes',
			)
		);
		$this->register_fake_resolver( array( 'bar_klarna' ), true );

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'klarna' => 'foo_klarna' ) );

		$this->assertFalse( $report['success'], 'A failing resolver makes the batch unsuccessful.' );
		$this->assertSame( 'failed', $report['results'][0]['disabled'][0]['status'], 'The failure is reported per target.' );
		$this->assertSame( 'yes', $this->gateway( 'foo_klarna' )->enabled, 'The kept implementation is never disabled.' );
	}

	/**
	 * @testdox Should not report success when there are no selections.
	 */
	public function test_empty_selections_is_not_success(): void {
		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array() );

		$this->assertFalse( $report['success'], 'An empty request is not a successful resolution.' );
		$this->assertSame( array(), $report['results'], 'There are no per-selection results.' );
	}

	/**
	 * @testdox Should never mutate an express duplicate submitted to the regular resolver.
	 */
	public function test_express_duplicate_is_not_mutated(): void {
		// Apple Pay / Google Pay overlap is reported by the baseline as an express duplicate. It is a
		// separate bucket, so it is never part of the regular resolvable set and never validated as
		// express — it simply cannot be resolved here and nothing is mutated.
		$this->register_fake_gateways(
			array(
				'applepay_button' => 'yes',
				'foo_google_pay'  => 'yes',
			)
		);

		$report = ( new PaymentMethodDuplicatesResolver() )->resolve( array( 'apple_pay_google_pay' => 'applepay_button' ) );

		$this->assertFalse( $report['success'], 'Express input must not report success.' );
		$this->assertSame( 'not_resolvable', $report['results'][0]['error'], 'It is not part of the regular resolvable set.' );
		$this->assertSame( 'yes', $this->gateway( 'applepay_button' )->enabled, 'No express gateway is mutated.' );
		$this->assertSame( 'yes', $this->gateway( 'foo_google_pay' )->enabled, 'No express gateway is mutated.' );
	}

	/**
	 * @testdox Should not resolve a Stripe method hidden by Optimized Checkout.
	 */
	public function test_optimized_checkout_hidden_child_is_not_resolvable(): void {
		$this->register_fake_gateways(
			array(
				'stripe_klarna'               => 'yes',
				'woocommerce_payments_klarna' => 'yes',
			)
		);

		$resolver = $this->resolver_with(
			array(
				'payment_methods' => array( 'klarna' => array( 'stripe_klarna', 'woocommerce_payments_klarna' ) ),
				'express'         => array(),
			),
			array(
				'stripe' => array(
					'childGatewayIds'   => array( 'stripe_klarna' ),
					'showChildren'      => false,
					'optimizedCheckout' => true,
					'settingsUrl'       => 'https://example.test',
				),
			)
		);

		$report = $resolver->resolve( array( 'klarna' => 'woocommerce_payments_klarna' ) );

		$this->assertFalse( $report['success'], 'An Optimized-Checkout-limited collision cannot be resolved in Step 1.' );
		$this->assertSame( 'not_resolvable', $report['results'][0]['error'], 'It is reported as not resolvable.' );
		$this->assertSame( 'yes', $this->gateway( 'stripe_klarna' )->enabled, 'The Optimized Checkout child is never mutated here.' );
	}

	/**
	 * @testdox Should return unsupported and mutate nothing when a grouped method has no resolver.
	 */
	public function test_grouped_method_without_resolver_is_unsupported_and_unchanged(): void {
		$this->register_fake_gateways(
			array(
				'prov_klarna'  => 'yes',
				'other_klarna' => 'yes',
			)
		);

		// 'prov_klarna' is a grouped child with no resolver, so it cannot be disabled — it becomes the
		// required keep. Disabling it (by keeping the other) must never be possible.
		$resolver = $this->resolver_with(
			array(
				'payment_methods' => array( 'klarna' => array( 'prov_klarna', 'other_klarna' ) ),
				'express'         => array(),
			),
			array(
				'prov' => array(
					'childGatewayIds'   => array( 'prov_klarna' ),
					'showChildren'      => true,
					'optimizedCheckout' => false,
					'settingsUrl'       => 'https://example.test',
				),
			)
		);

		$report = $resolver->resolve( array( 'klarna' => 'other_klarna' ) );

		$this->assertFalse( $report['success'], 'Keeping the disableable one instead of the required keep is rejected.' );
		$this->assertSame( 'required_keep_mismatch', $report['results'][0]['error'], 'The non-disableable implementation is the required keep.' );
		$this->assertSame( 'yes', $this->gateway( 'prov_klarna' )->enabled, 'The non-disableable target is never disabled.' );
		$this->assertSame( 'yes', $this->gateway( 'other_klarna' )->enabled, 'Nothing is mutated on a rejected selection.' );
	}

	/**
	 * @testdox Should keep the required implementation, disable the other, and never target the non-disableable one.
	 */
	public function test_required_keep_disables_only_the_other(): void {
		$this->register_fake_gateways(
			array(
				'woocommerce_payments' => 'yes',
				'stripe'               => 'yes',
			)
		);
		// WooPayments Card cannot be disabled; Stripe Card can.
		$resolver_spy = $this->register_fake_resolver(
			array( 'woocommerce_payments', 'stripe' ),
			false,
			array( 'woocommerce_payments' )
		);

		$resolver = $this->resolver_with(
			array(
				'payment_methods' => array( 'card' => array( 'woocommerce_payments', 'stripe' ) ),
				'express'         => array(),
			),
			array()
		);

		$report = $resolver->resolve( array( 'card' => 'woocommerce_payments' ) );

		$this->assertTrue( $report['success'], 'Keeping the required implementation resolves the duplicate.' );
		$this->assertSame( array( 'stripe' ), $resolver_spy->disabled_ids, 'Only Stripe Card is disabled; WooPayments Card is never passed to disable().' );
		$this->assertSame( 'no', $this->gateway( 'stripe' )->enabled, 'Stripe Card is disabled.' );
		$this->assertSame( 'yes', $this->gateway( 'woocommerce_payments' )->enabled, 'WooPayments Card stays enabled.' );
	}

	/**
	 * @testdox Should reject keeping Stripe for Card when WooPayments Card is the required keep, mutating nothing.
	 */
	public function test_rejects_keeping_stripe_when_card_requires_woopayments(): void {
		$this->register_fake_gateways(
			array(
				'woocommerce_payments' => 'yes',
				'stripe'               => 'yes',
			)
		);
		$resolver_spy = $this->register_fake_resolver(
			array( 'woocommerce_payments', 'stripe' ),
			false,
			array( 'woocommerce_payments' )
		);

		$resolver = $this->resolver_with(
			array(
				'payment_methods' => array( 'card' => array( 'woocommerce_payments', 'stripe' ) ),
				'express'         => array(),
			),
			array()
		);

		$report = $resolver->resolve( array( 'card' => 'stripe' ) );

		$this->assertFalse( $report['success'], 'Keeping Stripe for Card is not a valid selection.' );
		$this->assertSame( 'required_keep_mismatch', $report['results'][0]['error'], 'The required keep is WooPayments Card.' );
		$this->assertSame( array(), $resolver_spy->disabled_ids, 'No implementation is disabled on a rejected selection.' );
		$this->assertSame( 'yes', $this->gateway( 'woocommerce_payments' )->enabled, 'WooPayments Card is untouched.' );
		$this->assertSame( 'yes', $this->gateway( 'stripe' )->enabled, 'Stripe Card is untouched.' );
	}

	/**
	 * @testdox Should treat a group with more than one non-disableable implementation as unresolvable.
	 */
	public function test_multiple_non_disableable_is_unresolvable(): void {
		$this->register_fake_gateways(
			array(
				'a_card' => 'yes',
				'b_card' => 'yes',
			)
		);
		// Both implementations are non-disableable — keeping one still leaves another that cannot be
		// turned off, so the duplicate is not resolvable here.
		$resolver_spy = $this->register_fake_resolver(
			array( 'a_card', 'b_card' ),
			false,
			array( 'a_card', 'b_card' )
		);

		$resolver = $this->resolver_with(
			array(
				'payment_methods' => array( 'card' => array( 'a_card', 'b_card' ) ),
				'express'         => array(),
			),
			array()
		);

		$report = $resolver->resolve( array( 'card' => 'a_card' ) );

		$this->assertFalse( $report['success'], 'An unresolvable group cannot succeed.' );
		$this->assertSame( 'not_resolvable', $report['results'][0]['error'], 'The group is omitted from the resolvable candidates.' );
		$this->assertSame( array(), $resolver_spy->disabled_ids, 'No destructive mutation occurs.' );
		$this->assertSame( 'yes', $this->gateway( 'a_card' )->enabled, 'Nothing is disabled.' );
		$this->assertSame( 'yes', $this->gateway( 'b_card' )->enabled, 'Nothing is disabled.' );
	}

	/**
	 * @testdox Should use the generic resolver for a positively standalone one-to-one gateway.
	 */
	public function test_positively_standalone_gateway_uses_generic_resolver(): void {
		$this->register_fake_gateways(
			array(
				'a_klarna' => 'yes',
				'b_klarna' => 'yes',
			)
		);

		// No grouped providers: both gateways are standalone, surfaced individual methods.
		$resolver = $this->resolver_with(
			array(
				'payment_methods' => array( 'klarna' => array( 'a_klarna', 'b_klarna' ) ),
				'express'         => array(),
			),
			array()
		);

		$report = $resolver->resolve( array( 'klarna' => 'a_klarna' ) );

		$this->assertTrue( $report['success'], 'A standalone gateway resolves through the generic path.' );
		$this->assertSame( 'disabled', $report['results'][0]['disabled'][0]['status'], 'The generic resolver disabled it.' );
		$this->assertSame( 'no', $this->gateway( 'b_klarna' )->enabled, 'The non-kept standalone gateway is disabled.' );
	}

	/**
	 * A resolver subclass with a fixed detection result and grouped-providers representation.
	 *
	 * @param array $detected The detection result the resolver should see.
	 * @param array $grouped  The grouped-providers representation the resolver should see.
	 *
	 * @return PaymentMethodDuplicatesResolver
	 */
	private function resolver_with( array $detected, array $grouped ): PaymentMethodDuplicatesResolver {
		return new class( $detected, $grouped ) extends PaymentMethodDuplicatesResolver {
			/**
			 * The stubbed detection result.
			 *
			 * @var array
			 */
			private $detected_stub;

			/**
			 * The stubbed grouped-providers representation.
			 *
			 * @var array
			 */
			private $grouped_stub;

			/**
			 * Build the stubbed resolver.
			 *
			 * @param array $detected The detection result.
			 * @param array $grouped  The grouped-providers representation.
			 */
			public function __construct( array $detected, array $grouped ) {
				$this->detected_stub = $detected;
				$this->grouped_stub  = $grouped;
			}

			/**
			 * Return the stubbed detection result.
			 *
			 * @return array
			 */
			protected function detect(): array {
				return $this->detected_stub;
			}

			/**
			 * Return the stubbed grouped-providers representation.
			 *
			 * @return array
			 */
			protected function get_grouped_providers(): array {
				return $this->grouped_stub;
			}
		};
	}

	/**
	 * Register a fake integration resolver for the given gateway ids.
	 *
	 * @param string[] $ids             The gateway ids the resolver owns.
	 * @param bool     $fail            Whether the resolver should report failure instead of disabling.
	 * @param string[] $non_disableable The owned gateway ids that cannot be disabled individually.
	 *
	 * @return object The resolver instance (exposes the ids it was asked to disable).
	 */
	private function register_fake_resolver( array $ids, bool $fail = false, array $non_disableable = array() ): object {
		$resolver = new class( $ids, $fail, $non_disableable ) implements PaymentMethodDuplicateResolverInterface {
			/**
			 * The gateway ids this resolver owns.
			 *
			 * @var string[]
			 */
			private $ids;

			/**
			 * Whether to report failure.
			 *
			 * @var bool
			 */
			private $fail;

			/**
			 * The owned gateway ids that cannot be disabled individually.
			 *
			 * @var string[]
			 */
			private $non_disableable;

			/**
			 * The gateway ids this resolver was asked to disable.
			 *
			 * @var string[]
			 */
			public $disabled_ids = array();

			/**
			 * Build the fake resolver.
			 *
			 * @param string[] $ids             The owned gateway ids.
			 * @param bool     $fail            Whether to report failure.
			 * @param string[] $non_disableable The owned ids that cannot be disabled.
			 */
			public function __construct( array $ids, bool $fail, array $non_disableable ) {
				$this->ids             = $ids;
				$this->fail            = $fail;
				$this->non_disableable = $non_disableable;
			}

			/**
			 * Whether this resolver owns the id.
			 *
			 * @param string $gateway_id The gateway id.
			 *
			 * @return bool
			 */
			public function supports( string $gateway_id ): bool {
				return in_array( $gateway_id, $this->ids, true );
			}

			/**
			 * Whether the id can be disabled individually.
			 *
			 * @param string $gateway_id The gateway id.
			 *
			 * @return bool
			 */
			public function can_disable( string $gateway_id ): bool {
				return ! in_array( $gateway_id, $this->non_disableable, true );
			}

			/**
			 * Disable the method, recording the request.
			 *
			 * @param string $gateway_id The gateway id.
			 *
			 * @return array{status: string, message?: string}
			 */
			public function disable( string $gateway_id ): array {
				$this->disabled_ids[] = $gateway_id;

				if ( $this->fail ) {
					return array(
						'status'  => 'failed',
						'message' => 'Simulated failure.',
					);
				}

				$gateway = WC()->payment_gateways()->payment_gateways()[ $gateway_id ] ?? null;
				if ( $gateway instanceof WC_Payment_Gateway ) {
					$gateway->enabled = 'no';
				}

				return array( 'status' => 'disabled' );
			}
		};

		add_filter(
			'woocommerce_payment_method_duplicate_resolvers',
			static function ( $resolvers ) use ( $resolver ) {
				$resolvers[] = $resolver;
				return $resolvers;
			}
		);

		return $resolver;
	}

	/**
	 * The live gateway instance for an id.
	 *
	 * @param string $id The gateway id.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function gateway( string $id ): WC_Payment_Gateway {
		return WC()->payment_gateways()->payment_gateways()[ $id ];
	}

	/**
	 * Contribute canonical definitions to the detector, preserving the core baseline.
	 *
	 * @param array<string, array{keywords: string[], express: bool}> $definitions Definitions to add.
	 */
	private function contribute_definitions( array $definitions ): void {
		add_filter(
			'woocommerce_payment_method_duplicate_definitions',
			static function ( $baseline ) use ( $definitions ) {
				return array_merge( (array) $baseline, $definitions );
			}
		);
	}

	/**
	 * Register a deterministic set of fake payment gateways.
	 *
	 * @param array<string, string> $specs Map of gateway id to enabled flag ('yes'|'no'), in order.
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
