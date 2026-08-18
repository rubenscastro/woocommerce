/**
 * Internal dependencies
 */
import {
	calculateExpressImpact,
	calculatePrerequisiteImpact,
} from '../express-impact';
import type { ExpressControlUnit } from '../types';

/**
 * The canonical store shape, mirroring ExpressImpactCalculatorTest on the server.
 *
 * Apple Pay is offered by a provider with a JOINT wallet unit (WooPayments-shaped) and one with an
 * INDEPENDENT per-wallet unit (PayPal-shaped). Google Pay comes only from the joint provider.
 */
const units = ( jointCanDisable = true ): ExpressControlUnit[] => [
	{
		id: 'joint:wallets',
		providerSlug: 'joint-provider',
		providerLabel: 'Joint Provider',
		gatewayIds: [ 'joint_apple_pay', 'joint_google_pay' ],
		wallets: [ 'apple_pay', 'google_pay' ],
		enabled: true,
		canDisable: jointCanDisable,
		requires: [],
	},
	{
		id: 'indep:apple_pay',
		providerSlug: 'indep-provider',
		providerLabel: 'Independent Provider',
		gatewayIds: [ 'indep_apple_pay' ],
		wallets: [ 'apple_pay' ],
		enabled: true,
		canDisable: true,
		requires: [],
	},
];

describe( 'calculateExpressImpact', () => {
	it( 'reports the other wallet as a consequence when a joint unit is turned off', () => {
		const impact = calculateExpressImpact( units(), {
			apple_pay: 'indep:apple_pay',
		} );

		expect( impact.disable ).toEqual( [ 'joint:wallets' ] );
		expect( impact.lostWallets ).toEqual( [ 'google_pay' ] );
		expect( impact.consequences ).toEqual( [
			{
				walletId: 'google_pay',
				causedByUnitId: 'joint:wallets',
				providerLabel: 'Joint Provider',
			},
		] );
		expect( impact.conflicts ).toEqual( [] );
		expect( impact.blocked ).toEqual( [] );
	} );

	it( 'reports no consequence when the disabled unit controls only the chosen wallet', () => {
		const impact = calculateExpressImpact( units(), {
			apple_pay: 'joint:wallets',
		} );

		expect( impact.disable ).toEqual( [ 'indep:apple_pay' ] );
		expect( impact.lostWallets ).toEqual( [] );
		expect( impact.consequences ).toEqual( [] );
		expect( impact.conflicts ).toEqual( [] );
	} );

	it( 'reports a conflict when one selection turns off the provider another selection chose', () => {
		const impact = calculateExpressImpact( units(), {
			apple_pay: 'joint:wallets',
			google_pay: 'indep-provider',
		} );

		expect( impact.disable ).toContain( 'joint:wallets' );
		expect( impact.conflicts ).toEqual( [
			{ walletId: 'apple_pay', unitId: 'joint:wallets' },
		] );
	} );

	it( 'reports a unit that must be turned off but says it cannot be', () => {
		const impact = calculateExpressImpact( units( false ), {
			apple_pay: 'indep:apple_pay',
		} );

		expect( impact.blocked ).toEqual( [ 'joint:wallets' ] );
	} );

	it( 'does nothing without selections, and ignores empty values', () => {
		expect( calculateExpressImpact( units(), {} ).disable ).toEqual( [] );
		expect(
			calculateExpressImpact( units(), { apple_pay: '' } ).disable
		).toEqual( [] );
	} );

	it( 'accepts a provider slug as the selection, not only a control unit id', () => {
		const impact = calculateExpressImpact( units(), {
			apple_pay: 'indep-provider',
		} );

		expect( impact.disable ).toEqual( [ 'joint:wallets' ] );
	} );

	it( 'ignores units that are already disabled', () => {
		const disabledJoint = units().map( ( unit ) =>
			unit.id === 'joint:wallets' ? { ...unit, enabled: false } : unit
		);

		const impact = calculateExpressImpact( disabledJoint, {
			apple_pay: 'indep:apple_pay',
		} );

		expect( impact.disable ).toEqual( [] );
		expect( impact.consequences ).toEqual( [] );
	} );

	it( 'never reports the reassigned wallet itself as collateral', () => {
		const impact = calculateExpressImpact( units(), {
			google_pay: 'indep-provider',
		} );

		expect( impact.lostWallets ).toContain( 'google_pay' );
		expect( impact.consequences ).toEqual( [] );
	} );
} );

describe( 'calculatePrerequisiteImpact', () => {
	// Stripe serves its wallets off its card gateway and refuses to disable Card while they are on;
	// WooPayments offers the same wallets independently of Stripe.
	const withPrerequisites: ExpressControlUnit[] = [
		{
			id: 'stripe:express_checkout',
			providerSlug: 'woocommerce-gateway-stripe',
			providerLabel: 'Stripe',
			gatewayIds: [ 'stripe' ],
			wallets: [ 'apple_pay', 'google_pay' ],
			enabled: true,
			canDisable: true,
			requires: [ 'stripe' ],
		},
		{
			id: 'woopayments:wallets',
			providerSlug: 'woocommerce-payments',
			providerLabel: 'WooPayments',
			gatewayIds: [ 'woocommerce_payments' ],
			wallets: [ 'apple_pay', 'google_pay' ],
			enabled: true,
			canDisable: true,
			requires: [ 'woocommerce_payments' ],
		},
	];

	it( 'reports the express methods that go when their prerequisite is disabled', () => {
		const impact = calculatePrerequisiteImpact( withPrerequisites, [
			'stripe',
		] );

		expect( impact.brokenUnits.map( ( unit ) => unit.id ) ).toEqual( [
			'stripe:express_checkout',
		] );
		// They survive, because WooPayments still offers them — so this is reassurance, not a loss.
		expect( impact.lostMethods ).toEqual( [] );
		expect( impact.survivingMethods.sort() ).toEqual( [
			'apple_pay',
			'google_pay',
		] );
		expect( impact.survivingVia ).toEqual( [ 'WooPayments' ] );
	} );

	it( 'reports methods as lost when nobody else offers them', () => {
		const stripeOnly = withPrerequisites.filter(
			( unit ) => unit.id === 'stripe:express_checkout'
		);

		const impact = calculatePrerequisiteImpact( stripeOnly, [ 'stripe' ] );

		expect( impact.lostMethods.sort() ).toEqual( [
			'apple_pay',
			'google_pay',
		] );
		expect( impact.survivingMethods ).toEqual( [] );
		expect( impact.survivingVia ).toEqual( [] );
	} );

	it( "reports nothing when the disabled gateway is nobody's prerequisite", () => {
		const impact = calculatePrerequisiteImpact( withPrerequisites, [
			'mollie_wc_gateway_creditcard',
		] );

		expect( impact.brokenUnits ).toEqual( [] );
		expect( impact.lostMethods ).toEqual( [] );
	} );

	it( 'ignores units that are already disabled', () => {
		const impact = calculatePrerequisiteImpact(
			withPrerequisites.map( ( unit ) =>
				unit.id === 'stripe:express_checkout'
					? { ...unit, enabled: false }
					: unit
			),
			[ 'stripe' ]
		);

		expect( impact.brokenUnits ).toEqual( [] );
	} );
} );
