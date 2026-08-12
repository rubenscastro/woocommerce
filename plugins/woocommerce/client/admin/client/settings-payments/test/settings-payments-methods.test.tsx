/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { useDispatch } from '@wordpress/data';
import { getPaymentMethods } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import type { RegisteredPaymentMethod } from '@woocommerce/blocks-registry';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import {
	SettingsPaymentsMethods,
	serializePaymentMethodOrder,
	type Row,
} from '../settings-payments-methods';

jest.mock( '@woocommerce/settings', () => ( {
	getSetting: jest.fn(),
	getAdminLink: jest.fn( () => 'https://example.com/wp-admin/' ),
} ) );

jest.mock( '@woocommerce/data', () => ( {
	paymentSettingsStore: 'payment-settings-store',
} ) );

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useDispatch: jest.fn(),
} ) );

jest.mock( '~/settings-payments/components/sortable', () => ( {
	DefaultDragHandle: () => <div data-testid="drag-handle" />,
	SortableContainer: ( {
		items,
		setItems,
		children,
	}: {
		items: unknown[];
		setItems: ( items: unknown[] ) => void;
		children: ReactNode;
	} ) => (
		<div>
			<button
				type="button"
				onClick={ () => setItems( [ ...items ].reverse() ) }
			>
				test-reorder
			</button>
			{ children }
		</div>
	),
	SortableItem: ( { children }: { children: ReactNode } ) => (
		<div>{ children }</div>
	),
} ) );

jest.mock( '~/settings-payments/components/status-badge', () => ( {
	StatusBadge: () => <div data-testid="status-badge" />,
} ) );

jest.mock( '~/settings-payments/components/buttons', () => ( {
	BackButton: () => <button>Back</button>,
} ) );

const method = (
	name: string,
	extra: Partial< RegisteredPaymentMethod > = {}
): RegisteredPaymentMethod =>
	( {
		name,
		ariaLabel: name,
		label: name,
		icons: null,
		...extra,
	} ) as RegisteredPaymentMethod;

describe( 'serializePaymentMethodOrder', () => {
	it( 'emits a standalone method as its registry name', () => {
		const rows: Row[] = [
			{
				id: 'cod',
				paymentMethod: method( 'cod' ),
				group: null,
				children: [],
			},
		];

		expect( serializePaymentMethodOrder( rows ) ).toEqual( [ 'cod' ] );
	} );

	it( 'emits only the real method name for a grouped row with Optimized Checkout on', () => {
		const rows: Row[] = [
			{
				id: 'stripe',
				paymentMethod: method( 'stripe' ),
				group: {
					childGatewayIds: [ 'stripe_ideal' ],
					showChildren: false,
					optimizedCheckout: true,
					settingsUrl: '',
				},
				children: [],
			},
		];

		expect( serializePaymentMethodOrder( rows ) ).toEqual( [ 'stripe' ] );
	} );

	it( 'flattens the real child method names for a grouped row with Optimized Checkout off', () => {
		const rows: Row[] = [
			{
				id: 'stripe',
				paymentMethod: method( 'stripe' ),
				group: {
					childGatewayIds: [ 'stripe_ideal', 'stripe_bancontact' ],
					showChildren: true,
					optimizedCheckout: false,
					settingsUrl: '',
				},
				children: [
					method( 'stripe_ideal' ),
					method( 'stripe_bancontact' ),
				],
			},
		];

		expect( serializePaymentMethodOrder( rows ) ).toEqual( [
			'stripe',
			'stripe_ideal',
			'stripe_bancontact',
		] );
	} );

	it( 'emits each real method name when a provider exposes multiple methods', () => {
		const rows: Row[] = [
			{
				id: 'stripe',
				paymentMethod: method( 'stripe' ),
				group: null,
				children: [],
			},
			{
				id: 'stripe_p24',
				paymentMethod: method( 'stripe_p24' ),
				group: null,
				children: [],
			},
		];

		expect( serializePaymentMethodOrder( rows ) ).toEqual( [
			'stripe',
			'stripe_p24',
		] );
	} );

	it( 'never emits row/group ids or paymentMethodId, only registry names', () => {
		const rows: Row[] = [
			{
				id: 'posted_id',
				paymentMethod: method( 'real_name', {
					paymentMethodId: 'posted_id',
				} ),
				group: {
					childGatewayIds: [ 'child_posted' ],
					showChildren: true,
					optimizedCheckout: false,
					settingsUrl: '',
				},
				children: [
					method( 'child_real', { paymentMethodId: 'child_posted' } ),
				],
			},
		];

		const result = serializePaymentMethodOrder( rows );

		expect( result ).toEqual( [ 'real_name', 'child_real' ] );
		expect( result ).not.toContain( 'posted_id' );
		expect( result ).not.toContain( 'child_posted' );
	} );
} );

describe( 'SettingsPaymentsMethods', () => {
	let spikeSettings: Record< string, unknown >;
	let sortOrder: string[];
	let updateMock: jest.Mock;
	let successNoticeMock: jest.Mock;

	const setRegistry = ( methods: RegisteredPaymentMethod[] ) => {
		const record: Record< string, RegisteredPaymentMethod > = {};
		methods.forEach( ( m ) => {
			record[ m.name ] = m;
		} );
		( getPaymentMethods as jest.Mock ).mockReturnValue( record );
	};

	const renderedOrder = () =>
		Array.from(
			document.querySelectorAll( '.woocommerce-list__item-title' )
		).map( ( element ) => element.textContent );

	beforeEach( () => {
		spikeSettings = { paymentMethodOrder: null };
		sortOrder = [ 'cod', 'bacs', 'paypal' ];
		setRegistry( [
			method( 'cod' ),
			method( 'bacs' ),
			method( 'paypal' ),
		] );

		( getSetting as jest.Mock ).mockImplementation(
			( key: string, fallback: unknown ) => {
				if ( key === 'blocksPaymentMethodsSpike' ) {
					return spikeSettings;
				}
				if ( key === 'paymentMethodSortOrder' ) {
					return sortOrder;
				}
				return fallback;
			}
		);

		updateMock = jest.fn().mockResolvedValue( { success: true } );
		successNoticeMock = jest.fn();
		( useDispatch as jest.Mock ).mockImplementation( ( store: unknown ) => {
			if ( store === 'core/notices' ) {
				return { createSuccessNotice: successNoticeMock };
			}
			return {
				updatePaymentMethodOrder: updateMock,
			};
		} );
	} );

	it( 'disables Save when the order is unchanged', () => {
		render( <SettingsPaymentsMethods /> );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
	} );

	it( 'enables Save after a reorder and posts the canonical names in order', async () => {
		render( <SettingsPaymentsMethods /> );

		fireEvent.click( screen.getByText( 'test-reorder' ) );

		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( save ).toBeEnabled();

		fireEvent.click( save );

		await waitFor( () =>
			expect( updateMock ).toHaveBeenCalledWith( [
				'paypal',
				'bacs',
				'cod',
			] )
		);
	} );

	it( 'resets the dirty state after a successful save', async () => {
		render( <SettingsPaymentsMethods /> );

		fireEvent.click( screen.getByText( 'test-reorder' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Save' } )
			).toBeDisabled()
		);
	} );

	it( 'preserves the dirty state and shows an error when the save fails', async () => {
		updateMock.mockRejectedValue( new Error( 'request failed' ) );

		render( <SettingsPaymentsMethods /> );

		fireEvent.click( screen.getByText( 'test-reorder' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect(
			await screen.findAllByText( /could not be saved/i )
		).not.toHaveLength( 0 );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();
	} );

	it( 'shows a success snackbar after a successful save', async () => {
		render( <SettingsPaymentsMethods /> );

		fireEvent.click( screen.getByText( 'test-reorder' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () =>
			expect( successNoticeMock ).toHaveBeenCalledWith(
				expect.stringMatching( /saved/i ),
				expect.objectContaining( { type: 'snackbar' } )
			)
		);
	} );

	it( 'does not show a success snackbar when the save fails', async () => {
		updateMock.mockRejectedValue( new Error( 'request failed' ) );

		render( <SettingsPaymentsMethods /> );

		fireEvent.click( screen.getByText( 'test-reorder' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect(
			await screen.findAllByText( /could not be saved/i )
		).not.toHaveLength( 0 );
		expect( successNoticeMock ).not.toHaveBeenCalled();
	} );

	it( 'renders the current persisted order on initialization', () => {
		sortOrder = [ 'paypal', 'cod', 'bacs' ];

		render( <SettingsPaymentsMethods /> );

		expect( renderedOrder() ).toEqual( [ 'paypal', 'cod', 'bacs' ] );
	} );
} );
