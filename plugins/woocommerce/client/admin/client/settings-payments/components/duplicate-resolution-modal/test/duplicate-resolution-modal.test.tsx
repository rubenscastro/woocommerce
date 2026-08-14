/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { DuplicateResolutionModal } from '../duplicate-resolution-modal';
import type { DuplicateResolutionRow } from '../types';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useDispatch: jest.fn(),
} ) );

jest.mock( '@woocommerce/data', () => ( {
	paymentSettingsStore: 'payment-settings-store',
} ) );

// Two fully-selectable duplicates (both implementations can be disabled), the Klarna-like case.
const rows: DuplicateResolutionRow[] = [
	{
		canonicalId: 'card',
		icon: <span>card-icon</span>,
		label: 'Credit / Debit Cards',
		requiredKeepGatewayId: null,
		options: [
			{
				gatewayId: 'woocommerce_payments',
				providerSlug: 'woocommerce-payments',
				providerLabel: 'WooPayments',
				providerIcon: '',
				canDisable: true,
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
	{
		canonicalId: 'klarna',
		icon: <span>klarna-icon</span>,
		label: 'Klarna',
		requiredKeepGatewayId: null,
		options: [
			{
				gatewayId: 'woocommerce_payments_klarna',
				providerSlug: 'woocommerce-payments',
				providerLabel: 'WooPayments',
				providerIcon: '',
				canDisable: true,
			},
			{
				gatewayId: 'stripe_klarna',
				providerSlug: 'woocommerce-gateway-stripe',
				providerLabel: 'Stripe',
				providerIcon: '',
				canDisable: true,
			},
		],
	},
];

// A duplicate whose WooPayments implementation cannot be disabled (Card), so WooPayments is the
// required keep.
const cardRequiredRow: DuplicateResolutionRow = {
	canonicalId: 'card',
	icon: <span>card-icon</span>,
	label: 'Credit / Debit Cards',
	requiredKeepGatewayId: 'woocommerce_payments',
	options: [
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
};

const klarnaRow: DuplicateResolutionRow = {
	canonicalId: 'klarna',
	icon: <span>klarna-icon</span>,
	label: 'Klarna',
	requiredKeepGatewayId: null,
	options: [
		{
			gatewayId: 'woocommerce_payments_klarna',
			providerSlug: 'woocommerce-payments',
			providerLabel: 'WooPayments',
			providerIcon: '',
			canDisable: true,
		},
		{
			gatewayId: 'stripe_klarna',
			providerSlug: 'woocommerce-gateway-stripe',
			providerLabel: 'Stripe',
			providerIcon: '',
			canDisable: true,
		},
	],
};

const setDispatch = ( resolve: jest.Mock ) => {
	( useDispatch as jest.Mock ).mockReturnValue( {
		resolvePaymentMethodDuplicates: resolve,
	} );
};

const chooseAll = async () => {
	const selects = screen.getAllByRole( 'combobox' );
	await userEvent.selectOptions( selects[ 0 ], 'woocommerce_payments' );
	await userEvent.selectOptions(
		selects[ 1 ],
		'woocommerce_payments_klarna'
	);
};

describe( 'DuplicateResolutionModal', () => {
	const originalLocation = window.location;
	let reloadMock: jest.Mock;

	beforeEach( () => {
		reloadMock = jest.fn();
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { ...originalLocation, reload: reloadMock },
		} );
	} );

	afterEach( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: originalLocation,
		} );
		jest.clearAllMocks();
	} );

	it( 'renders one row per resolvable duplicate with a placeholder option', () => {
		setDispatch( jest.fn() );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressItems={ [] }
				onClose={ jest.fn() }
			/>
		);

		expect(
			screen.getByText( 'Credit / Debit Cards' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Klarna' ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'combobox' ) ).toHaveLength( 2 );
		expect(
			screen.getAllByRole( 'option', { name: 'Choose a provider' } )
		).toHaveLength( 2 );
	} );

	it( 'keeps the apply action disabled until every duplicate has a choice', async () => {
		setDispatch( jest.fn() );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressItems={ [] }
				onClose={ jest.fn() }
			/>
		);

		const apply = screen.getByRole( 'button', { name: 'Apply' } );
		expect( apply ).toBeDisabled();

		const selects = screen.getAllByRole( 'combobox' );
		await userEvent.selectOptions( selects[ 0 ], 'woocommerce_payments' );
		expect( apply ).toBeDisabled();

		await userEvent.selectOptions(
			selects[ 1 ],
			'woocommerce_payments_klarna'
		);
		expect( apply ).toBeEnabled();
	} );

	it( 'submits the chosen selections and reloads on success', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressItems={ [] }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		expect( resolve ).toHaveBeenCalledWith( {
			card: 'woocommerce_payments',
			klarna: 'woocommerce_payments_klarna',
		} );
		await waitFor( () => expect( reloadMock ).toHaveBeenCalled() );
	} );

	it( 'lists the methods that failed and does not reload when resolution is unsuccessful', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: false,
			results: [
				{
					canonicalId: 'klarna',
					kept: 'woocommerce_payments_klarna',
					disabled: [
						{
							gatewayId: 'stripe_klarna',
							status: 'failed',
							message: 'boom',
						},
					],
					error: null,
				},
			],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressItems={ [] }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		// The error names the affected method and the provider it failed for.
		await waitFor( () =>
			expect(
				screen.getAllByText( /Klarna \(Stripe\)/ ).length
			).toBeGreaterThan( 0 )
		);
		expect( reloadMock ).not.toHaveBeenCalled();
	} );

	it( 'walks through the express step without submitting express selections', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressItems={ [
					{
						label: 'Apple Pay / Google Pay',
						gatewayIds: [ 'applepay', 'googlepay' ],
					},
				] }
				onClose={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Step 1 of 2' ) ).toBeInTheDocument();

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);

		expect( screen.getByText( 'Step 2 of 2' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Apple Pay / Google Pay' )
		).toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		// The submitted payload only contains the regular (Step 1) selections.
		expect( resolve ).toHaveBeenCalledWith( {
			card: 'woocommerce_payments',
			klarna: 'woocommerce_payments_klarna',
		} );
	} );

	it( 'returns to the first step from the express step', async () => {
		setDispatch( jest.fn() );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressItems={ [
					{
						label: 'Apple Pay / Google Pay',
						gatewayIds: [ 'applepay', 'googlepay' ],
					},
				] }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		expect( screen.getByText( 'Step 2 of 2' ) ).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: 'Back' } ) );
		expect( screen.getByText( 'Step 1 of 2' ) ).toBeInTheDocument();
	} );

	it( 'preselects and locks the required keep, and submits it, when one implementation cannot be disabled', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ [ cardRequiredRow ] }
				expressItems={ [] }
				onClose={ jest.fn() }
			/>
		);

		const select = screen.getByRole( 'combobox' );
		// WooPayments is preselected and the control is locked so Stripe cannot be chosen.
		expect( select ).toBeDisabled();
		expect( select ).toHaveValue( 'woocommerce_payments' );

		// No user choice is required — the action is immediately available.
		const apply = screen.getByRole( 'button', { name: 'Apply' } );
		expect( apply ).toBeEnabled();

		await userEvent.click( apply );

		// The kept implementation is WooPayments (never Stripe).
		expect( resolve ).toHaveBeenCalledWith( {
			card: 'woocommerce_payments',
		} );
	} );

	it( 'keeps an independently disableable duplicate selectable with no default', () => {
		setDispatch( jest.fn() );

		render(
			<DuplicateResolutionModal
				rows={ [ klarnaRow ] }
				expressItems={ [] }
				onClose={ jest.fn() }
			/>
		);

		const select = screen.getByRole( 'combobox' );
		expect( select ).toBeEnabled();
		expect( select ).toHaveValue( '' );
		expect(
			screen.getByRole( 'option', { name: 'WooPayments' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'option', { name: 'Stripe' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Apply' } )
		).toBeDisabled();
	} );

	it( 'requires a choice only for the selectable duplicate when Card is locked', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ [ cardRequiredRow, klarnaRow ] }
				expressItems={ [] }
				onClose={ jest.fn() }
			/>
		);

		const apply = screen.getByRole( 'button', { name: 'Apply' } );
		// Card is already satisfied (locked to WooPayments); Klarna still needs a choice.
		expect( apply ).toBeDisabled();

		const [ cardSelect, klarnaSelect ] = screen.getAllByRole( 'combobox' );
		expect( cardSelect ).toBeDisabled();
		expect( klarnaSelect ).toBeEnabled();

		await userEvent.selectOptions(
			klarnaSelect,
			'woocommerce_payments_klarna'
		);
		expect( apply ).toBeEnabled();

		await userEvent.click( apply );

		expect( resolve ).toHaveBeenCalledWith( {
			card: 'woocommerce_payments',
			klarna: 'woocommerce_payments_klarna',
		} );
	} );
} );
