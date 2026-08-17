/**
 * External dependencies
 */
import type {
	PaymentsProvider,
	PaymentGatewayProvider,
	SuggestedPaymentsExtension,
} from '@woocommerce/data';

/**
 * Internal dependencies
 */
import {
	groupProvidersByExtension,
	type ProviderListItem,
} from '../group-providers-by-extension';

const Gateway = 'gateway' as const;
const Suggestion = 'suggestion' as const;
const OfflineGroup = 'offline_pms_group' as const;

const gateway = (
	id: string,
	overrides: Partial< PaymentGatewayProvider > = {}
): PaymentsProvider =>
	( {
		id,
		_type: Gateway,
		_order: 0,
		title: id,
		icon: `https://example.com/${ id }.svg`,
		plugin: { slug: 'plugin-a', file: '', status: 'active' },
		management: { _links: { settings: { href: `/settings/${ id }` } } },
		...overrides,
	} ) as unknown as PaymentsProvider;

const suggestion = (
	id: string,
	title: string,
	icon: string
): SuggestedPaymentsExtension =>
	( { id, title, icon } ) as unknown as SuggestedPaymentsExtension;

const groups = ( items: ProviderListItem[] ) =>
	items.filter(
		( item ): item is Extract< ProviderListItem, { type: 'group' } > =>
			item.type === 'group'
	);

const singleIds = ( items: ProviderListItem[] ) =>
	items
		.filter(
			(
				item
			): item is Extract< ProviderListItem, { type: 'provider' } > =>
				item.type === 'provider'
		)
		.map( ( item ) => item.provider.id );

describe( 'groupProvidersByExtension', () => {
	it( 'groups two or more gateways that share a suggestion id', () => {
		const providers = [
			gateway( 'ppcp_blik', { _suggestion_id: 'paypal' } ),
			gateway( 'ppcp_eps', { _suggestion_id: 'paypal' } ),
			gateway( 'ppcp_ideal', { _suggestion_id: 'paypal' } ),
		];

		const result = groupProvidersByExtension( providers, {
			paypal: suggestion(
				'paypal',
				'PayPal Payments',
				'https://example.com/paypal.svg'
			),
		} );

		expect( result ).toHaveLength( 1 );
		const [ group ] = groups( result );
		expect( group.group.title ).toBe( 'PayPal Payments' );
		expect( group.group.icon ).toBe( 'https://example.com/paypal.svg' );
		expect( group.group.children.map( ( c ) => c.id ) ).toEqual( [
			'ppcp_blik',
			'ppcp_eps',
			'ppcp_ideal',
		] );
	} );

	it( 'does not group a single-gateway extension', () => {
		const providers = [
			gateway( 'woocommerce_payments', {
				_suggestion_id: 'woopayments',
			} ),
		];

		const result = groupProvidersByExtension( providers );

		expect( groups( result ) ).toHaveLength( 0 );
		expect( singleIds( result ) ).toEqual( [ 'woocommerce_payments' ] );
	} );

	it( 'falls back to the plugin slug when suggestion ids are absent', () => {
		const providers = [
			gateway( 'square', {
				_suggestion_id: undefined,
				plugin: {
					slug: 'woocommerce-square',
					file: '',
					status: 'active',
				},
			} ),
			gateway( 'square_cash_app', {
				_suggestion_id: undefined,
				plugin: {
					slug: 'woocommerce-square',
					file: '',
					status: 'active',
				},
			} ),
		];

		const [ group ] = groups( groupProvidersByExtension( providers ) );

		expect( group.group.children.map( ( c ) => c.id ) ).toEqual( [
			'square',
			'square_cash_app',
		] );
		// Humanized slug fallback when no suggestion is available.
		expect( group.group.title ).toBe( 'Square' );
	} );

	it( 'passes suggestions and the offline group through untouched', () => {
		const providers = [
			gateway( 'ppcp_blik', { _suggestion_id: 'paypal' } ),
			gateway( 'ppcp_eps', { _suggestion_id: 'paypal' } ),
			{ id: 'a_suggestion', _type: Suggestion } as PaymentsProvider,
			{ id: 'offline', _type: OfflineGroup } as PaymentsProvider,
		];

		const result = groupProvidersByExtension( providers, {
			paypal: suggestion( 'paypal', 'PayPal', 'x' ),
		} );

		expect( result.map( ( item ) => item.type ) ).toEqual( [
			'group',
			'provider',
			'provider',
		] );
		expect( singleIds( result ) ).toEqual( [ 'a_suggestion', 'offline' ] );
	} );

	it( 'emits the group at the first child position and pulls in interleaved siblings', () => {
		const providers = [
			gateway( 'ppcp_blik', { _suggestion_id: 'paypal' } ),
			gateway( 'stripe', { _suggestion_id: 'stripe' } ),
			gateway( 'ppcp_eps', { _suggestion_id: 'paypal' } ),
		];

		const result = groupProvidersByExtension( providers );

		// PayPal group at position 0 (its first child), stripe (single) after it.
		expect( result[ 0 ].type ).toBe( 'group' );
		expect(
			groups( result )[ 0 ].group.children.map( ( c ) => c.id )
		).toEqual( [ 'ppcp_blik', 'ppcp_eps' ] );
		expect( singleIds( result ) ).toEqual( [ 'stripe' ] );
	} );

	it( 'sets a shared settings URL only when every child points at the same one', () => {
		const same = [
			gateway( 'a', {
				_suggestion_id: 'ext',
				management: { _links: { settings: { href: '/settings/ext' } } },
			} ),
			gateway( 'b', {
				_suggestion_id: 'ext',
				management: { _links: { settings: { href: '/settings/ext' } } },
			} ),
		];
		const different = [
			gateway( 'c', {
				_suggestion_id: 'ext2',
				management: { _links: { settings: { href: '/settings/c' } } },
			} ),
			gateway( 'd', {
				_suggestion_id: 'ext2',
				management: { _links: { settings: { href: '/settings/d' } } },
			} ),
		];

		expect(
			groups( groupProvidersByExtension( same ) )[ 0 ].group
				.sharedSettingsUrl
		).toBe( '/settings/ext' );
		expect(
			groups( groupProvidersByExtension( different ) )[ 0 ].group
				.sharedSettingsUrl
		).toBeUndefined();
	} );

	it( 'treats gateways with the same settings screen as shared despite transient query args', () => {
		const children = [
			gateway( 'a', {
				_suggestion_id: 'ppcp',
				management: {
					_links: {
						settings: {
							href: 'admin.php?page=wc-settings&tab=checkout&section=ppcp&from=A',
						},
					},
				},
			} ),
			gateway( 'b', {
				_suggestion_id: 'ppcp',
				management: {
					_links: {
						settings: {
							href: 'https://store.test/wp-admin/admin.php?page=wc-settings&tab=checkout&section=ppcp&from=B',
						},
					},
				},
			} ),
		];

		expect(
			groups( groupProvidersByExtension( children ) )[ 0 ].group
				.sharedSettingsUrl
		).toBe( 'admin.php?page=wc-settings&tab=checkout&section=ppcp&from=A' );
	} );

	it( 'does not share a settings URL when the section differs', () => {
		const children = [
			gateway( 'a', {
				_suggestion_id: 'ext',
				management: {
					_links: {
						settings: {
							href: 'admin.php?page=wc-settings&tab=checkout&section=a',
						},
					},
				},
			} ),
			gateway( 'b', {
				_suggestion_id: 'ext',
				management: {
					_links: {
						settings: {
							href: 'admin.php?page=wc-settings&tab=checkout&section=b',
						},
					},
				},
			} ),
		];

		expect(
			groups( groupProvidersByExtension( children ) )[ 0 ].group
				.sharedSettingsUrl
		).toBeUndefined();
	} );

	it( 'collapses PayPal to its main gateway settings despite per-section links', () => {
		// PayPal links each gateway to its own `section=ppcp-<id>`, but they all open the same screen,
		// so the PayPal-scoped exception shares the main `ppcp-gateway` settings URL.
		const paypal = ( id: string ) =>
			gateway( id, {
				_suggestion_id: 'paypal_full_stack',
				plugin: {
					slug: 'woocommerce-paypal-payments',
					file: '',
					status: 'active',
				},
				management: {
					_links: {
						settings: {
							href: `admin.php?page=wc-settings&tab=checkout&section=${ id }`,
						},
					},
				},
			} );

		const result = groups(
			groupProvidersByExtension( [
				paypal( 'ppcp-ideal' ),
				paypal( 'ppcp-gateway' ),
				paypal( 'ppcp-blik' ),
			] )
		);

		expect( result[ 0 ].group.sharedSettingsUrl ).toBe(
			'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway'
		);
	} );

	it( 'falls back to the first PayPal child when the main gateway is absent', () => {
		const paypal = ( id: string ) =>
			gateway( id, {
				_suggestion_id: 'paypal_full_stack',
				plugin: {
					slug: 'woocommerce-paypal-payments',
					file: '',
					status: 'active',
				},
				management: {
					_links: {
						settings: {
							href: `admin.php?page=wc-settings&tab=checkout&section=${ id }`,
						},
					},
				},
			} );

		const result = groups(
			groupProvidersByExtension( [
				paypal( 'ppcp-ideal' ),
				paypal( 'ppcp-blik' ),
			] )
		);

		expect( result[ 0 ].group.sharedSettingsUrl ).toBe(
			'admin.php?page=wc-settings&tab=checkout&section=ppcp-ideal'
		);
	} );

	it( 'does not merge gateways that only share an undefined suggestion id', () => {
		const providers = [
			gateway( 'x', {
				_suggestion_id: undefined,
				plugin: { slug: 'plugin-x', file: '', status: 'active' },
			} ),
			gateway( 'y', {
				_suggestion_id: undefined,
				plugin: { slug: 'plugin-y', file: '', status: 'active' },
			} ),
		];

		const result = groupProvidersByExtension( providers );

		expect( groups( result ) ).toHaveLength( 0 );
		expect( singleIds( result ) ).toEqual( [ 'x', 'y' ] );
	} );
} );
