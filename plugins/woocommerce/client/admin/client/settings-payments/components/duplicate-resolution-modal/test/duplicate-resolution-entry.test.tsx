/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import {
	buildDuplicateResolutionRows,
	buildExpressItems,
} from '../duplicate-resolution-entry';
import type { DuplicateProviders } from '../types';

describe( 'buildDuplicateResolutionRows', () => {
	const duplicateProviders: DuplicateProviders = {
		card: {
			methodLabel: 'Card',
			methodIcon: 'https://example.test/generic-card.svg',
			requiredKeepGatewayId: 'woocommerce_payments',
			implementations: [
				{
					gatewayId: 'woocommerce_payments',
					providerSlug: 'woocommerce-payments',
					providerLabel: 'WooPayments',
					providerIcon: '',
					canDisable: false,
				},
				{
					gatewayId: 'stripe',
					providerSlug: 'woocommerce-gateway-stripe',
					providerLabel: 'Stripe',
					providerIcon: '',
					canDisable: true,
				},
			],
		},
	};

	it( 'builds one row per canonical from the server payload, carrying options and required keep', () => {
		const rows = buildDuplicateResolutionRows( duplicateProviders );

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].canonicalId ).toBe( 'card' );
		expect( rows[ 0 ].label ).toBe( 'Card' );
		expect( rows[ 0 ].requiredKeepGatewayId ).toBe(
			'woocommerce_payments'
		);
		expect( rows[ 0 ].options.map( ( o ) => o.providerLabel ) ).toEqual( [
			'WooPayments',
			'Stripe',
		] );
	} );

	it( 'renders the server-provided method icon (the generic Card icon, not a provider logo)', () => {
		const rows = buildDuplicateResolutionRows( duplicateProviders );
		const { container } = render( <>{ rows[ 0 ].icon }</> );
		const img = container.querySelector( 'img' );

		expect( img ).toHaveAttribute(
			'src',
			'https://example.test/generic-card.svg'
		);
	} );

	it( 'falls back to a placeholder when no method icon is provided', () => {
		const rows = buildDuplicateResolutionRows( {
			card: { ...duplicateProviders.card, methodIcon: '' },
		} );
		const { container } = render( <>{ rows[ 0 ].icon }</> );

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect(
			container.querySelector(
				'.duplicate-resolution-modal__row-placeholder'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'buildExpressItems', () => {
	it( 'maps express duplicates to labelled items, combining Apple Pay / Google Pay', () => {
		expect(
			buildExpressItems( {
				apple_pay_google_pay: [ 'applepay', 'googlepay' ],
			} )
		).toEqual( [
			{
				label: 'Apple Pay / Google Pay',
				gatewayIds: [ 'applepay', 'googlepay' ],
			},
		] );
	} );

	it( 'returns nothing when there are no express duplicates', () => {
		expect( buildExpressItems() ).toEqual( [] );
	} );
} );
