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
import type {
	DuplicateResolutionRow,
	ExpressControlUnit,
	ExpressDuplicateGroup,
} from '../types';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useDispatch: jest.fn(),
} ) );

jest.mock( '@woocommerce/data', () => ( {
	paymentSettingsStore: 'payment-settings-store',
} ) );

// Scenario: WooPayments bundles Apple Pay + Google Pay behind one setting; PayPal offers Apple Pay
// only. Both methods are therefore decided together, and picking PayPal costs Google Pay.
const expressControlUnits: ExpressControlUnit[] = [
	{
		id: 'woopayments:wallets',
		providerSlug: 'woocommerce-payments',
		providerLabel: 'WooPayments',
		gatewayIds: [
			'woocommerce_payments_apple_pay',
			'woocommerce_payments_google_pay',
		],
		wallets: [ 'apple_pay', 'google_pay' ],
		enabled: true,
		canDisable: true,
		requires: [ 'woocommerce_payments' ],
	},
	{
		id: 'ppcp:apple_pay',
		providerSlug: 'woocommerce-paypal-payments',
		providerLabel: 'PayPal',
		gatewayIds: [ 'ppcp-applepay' ],
		wallets: [ 'apple_pay' ],
		enabled: true,
		canDisable: true,
		requires: [ 'ppcp-gateway' ],
	},
];

const expressGroups: ExpressDuplicateGroup[] = [
	{
		id: 'apple_pay+google_pay',
		walletIds: [ 'apple_pay', 'google_pay' ],
		label: 'Apple Pay and Google Pay',
		icons: [],
		options: [
			{
				providerSlug: 'woocommerce-payments',
				providerLabel: 'WooPayments',
				providerIcon: '',
				controlUnitIds: [ 'woopayments:wallets' ],
				covers: [ 'apple_pay', 'google_pay' ],
				supportsDisabled: [],
				canDisable: true,
			},
			{
				providerSlug: 'woocommerce-paypal-payments',
				providerLabel: 'PayPal',
				providerIcon: '',
				controlUnitIds: [ 'ppcp:apple_pay' ],
				covers: [ 'apple_pay' ],
				supportsDisabled: [],
				canDisable: true,
			},
		],
	},
];

const walletLabels: Record< string, string > = {
	apple_pay: 'Apple Pay',
	google_pay: 'Google Pay',
};

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

const setDispatch = ( resolve: jest.Mock, resolveExpress?: jest.Mock ) => {
	( useDispatch as jest.Mock ).mockReturnValue( {
		resolvePaymentMethodDuplicates: resolve,
		resolveExpressMethodDuplicates:
			resolveExpress ??
			jest.fn().mockResolvedValue( {
				success: true,
				error: null,
				disabled: [],
				lost_wallets: [],
				duplicates: {},
			} ),
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
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
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
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
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

	it( 'submits the chosen selections, shows the server diagnostic, and reloads on Done', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [
				{
					canonicalId: 'card',
					kept: 'woocommerce_payments',
					disabled: [
						{
							gatewayId: 'stripe',
							status: 'disabled',
							message: '',
						},
					],
					error: null,
				},
				{
					canonicalId: 'klarna',
					kept: 'woocommerce_payments_klarna',
					disabled: [
						{
							gatewayId: 'stripe_klarna',
							status: 'disabled',
							message: '',
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
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
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

		// The diagnostic reflects the server report: what was kept and what was disabled, per method.
		const doneButton = await screen.findByRole( 'button', {
			name: 'Done',
		} );
		expect( screen.getAllByText( /— kept/ ).length ).toBeGreaterThan( 0 );
		expect( screen.getAllByText( /— disabled/ ).length ).toBeGreaterThan(
			0
		);
		// It does not reload until the merchant closes the diagnostic.
		expect( reloadMock ).not.toHaveBeenCalled();

		await userEvent.click( doneButton );
		await waitFor( () => expect( reloadMock ).toHaveBeenCalled() );
	} );

	it( 'surfaces the per-target failure in the diagnostic and does not reload when resolution is unsuccessful', async () => {
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
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		// The diagnostic surfaces the real per-target failure reason, not a generic message.
		await waitFor( () =>
			expect(
				screen.getAllByText( /failed: boom/ ).length
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
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
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
			screen.getByText( 'Apple Pay and Google Pay' )
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

	it( 'presents methods a provider controls together as a single decision', async () => {
		setDispatch( jest.fn() );
		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);

		// One control for the pair, not one per method.
		expect(
			screen.getByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay and Google Pay',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'warns about the method a partial provider does not carry', async () => {
		setDispatch( jest.fn() );
		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay and Google Pay',
			} ),
			'woocommerce-paypal-payments'
		);

		expect(
			screen.getByText( 'Google Pay will also be disabled' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'PayPal does not offer Google Pay, so choosing it here will turn it off.'
			)
		).toBeInTheDocument();
		// A consequence is an accepted outcome, so the merchant can still continue.
		expect( screen.getByRole( 'button', { name: 'Apply' } ) ).toBeEnabled();
	} );

	it( 'says a method the partial provider merely has switched off can be enabled instead', async () => {
		// Same shape as above, except PayPal does offer Google Pay — the merchant just has it off.
		const switchedOffGroups: ExpressDuplicateGroup[] = [
			{
				...expressGroups[ 0 ],
				options: expressGroups[ 0 ].options.map( ( option ) =>
					option.providerSlug === 'woocommerce-paypal-payments'
						? { ...option, supportsDisabled: [ 'google_pay' ] }
						: option
				),
			},
		];

		setDispatch( jest.fn() );
		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ switchedOffGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay and Google Pay',
			} ),
			'woocommerce-paypal-payments'
		);

		expect(
			screen.getByText(
				'PayPal offers Google Pay, but it is turned off. Enable it in PayPal settings to keep offering it.'
			)
		).toBeInTheDocument();
		// The provider does offer it, so saying otherwise would be a lie the merchant can disprove.
		expect(
			screen.queryByText(
				'PayPal does not offer Google Pay, so choosing it here will turn it off.'
			)
		).not.toBeInTheDocument();
	} );

	it( 'shows no warning when the chosen provider carries the whole group', async () => {
		setDispatch( jest.fn() );
		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay and Google Pay',
			} ),
			'woocommerce-payments'
		);

		expect(
			document.querySelector(
				'.duplicate-resolution-modal__express-consequence'
			)
		).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Apply' } ) ).toBeEnabled();
	} );

	it( 'submits the express choice with the impact the merchant was shown', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		const resolveExpress = jest.fn().mockResolvedValue( {
			success: true,
			error: null,
			disabled: [
				{
					controlUnitId: 'woopayments:wallets',
					status: 'disabled',
					message: '',
				},
			],
			lost_wallets: [ 'google_pay' ],
			duplicates: {},
		} );
		setDispatch( resolve, resolveExpress );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay and Google Pay',
			} ),
			'woocommerce-paypal-payments'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		// The wallets the merchant was shown as being lost travel with the request, so the server can
		// refuse if the store changed since the preview.
		expect( resolveExpress ).toHaveBeenCalledWith(
			{ apple_pay: 'woocommerce-paypal-payments' },
			[ 'google_pay' ]
		);
		// The regular payload is unchanged by the presence of express choices.
		expect( resolve ).toHaveBeenCalledWith( {
			card: 'woocommerce_payments',
			klarna: 'woocommerce_payments_klarna',
		} );
	} );

	it( 'does not call the express endpoint when no express choice was made', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		const resolveExpress = jest.fn();
		setDispatch( resolve, resolveExpress );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		expect( resolveExpress ).not.toHaveBeenCalled();
	} );

	it( 'reports the express result on the final step, marking kept and disabled', async () => {
		const resolveExpress = jest.fn().mockResolvedValue( {
			success: true,
			error: null,
			disabled: [
				{
					controlUnitId: 'woopayments:wallets',
					status: 'disabled',
					message: '',
				},
			],
			lost_wallets: [ 'google_pay' ],
			duplicates: {},
		} );
		setDispatch(
			jest.fn().mockResolvedValue( {
				success: true,
				results: [],
				duplicates: {},
			} ),
			resolveExpress
		);

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay and Google Pay',
			} ),
			'woocommerce-paypal-payments'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		expect(
			await screen.findByText( 'Express checkout' )
		).toBeInTheDocument();

		// What was kept reads as kept, and what went away reads as gone.
		const kept = document.querySelectorAll(
			'.duplicate-resolution-modal__diagnostic-lines .is-kept'
		);
		const removed = document.querySelectorAll(
			'.duplicate-resolution-modal__diagnostic-lines .is-disabled'
		);

		expect(
			Array.from( kept ).some(
				( line ) => line.textContent?.includes( 'PayPal' )
			)
		).toBe( true );
		expect(
			Array.from( removed ).some(
				( line ) => line.textContent?.includes( 'WooPayments' )
			)
		).toBe( true );
		// The method that went with the unit is listed too, not just the provider.
		expect(
			Array.from( removed ).some(
				( line ) => line.textContent?.includes( 'Google Pay' )
			)
		).toBe( true );
	} );

	it( 'reports a refused express choice in the diagnostic', async () => {
		const resolveExpress = jest.fn().mockResolvedValue( {
			success: false,
			error: 'stale_preview',
			disabled: [],
			lost_wallets: [],
			duplicates: {},
		} );
		setDispatch(
			jest.fn().mockResolvedValue( {
				success: true,
				results: [],
				duplicates: {},
			} ),
			resolveExpress
		);

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await chooseAll();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', {
				name: 'Choose a provider for Apple Pay and Google Pay',
			} ),
			'woocommerce-paypal-payments'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		// The regular results still have to be visible, with the express refusal alongside them
		// rather than replacing the whole view with a generic error.
		expect(
			await screen.findByText( 'Express checkout' )
		).toBeInTheDocument();
		expect( screen.getByText( /stale_preview/ ) ).toBeInTheDocument();
	} );

	it( 'returns to the first step from the express step', async () => {
		setDispatch( jest.fn() );

		render(
			<DuplicateResolutionModal
				rows={ rows }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
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

	it( 'offers the required keep as an opt-in toggle that submits it when on', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ [ cardRequiredRow ] }
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
				onClose={ jest.fn() }
			/>
		);

		// The required-keep row is a checkbox, not a select, and starts unticked — so nothing is applied
		// until the merchant opts in.
		expect( screen.queryByRole( 'combobox' ) ).not.toBeInTheDocument();
		const checkbox = screen.getByRole( 'checkbox', {
			name: /Make WooPayments the default/,
		} );
		expect( checkbox ).not.toBeChecked();

		const apply = screen.getByRole( 'button', { name: 'Apply' } );
		expect( apply ).toBeDisabled();

		await userEvent.click( checkbox );
		expect( apply ).toBeEnabled();

		await userEvent.click( apply );

		// The kept implementation is WooPayments (never Stripe).
		expect( resolve ).toHaveBeenCalledWith( {
			card: 'woocommerce_payments',
		} );
	} );

	it( 'leaves the required-keep method untouched when its toggle is off', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ [ cardRequiredRow, klarnaRow ] }
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
				onClose={ jest.fn() }
			/>
		);

		// Step 1 is the required-keep opt-in; leave it untouched and move on.
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		// Step 2 is the provider choice; resolve only Klarna.
		await userEvent.selectOptions(
			screen.getByRole( 'combobox' ),
			'woocommerce_payments_klarna'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply' } )
		);

		// Card is omitted from the payload entirely, so it is left as is.
		expect( resolve ).toHaveBeenCalledWith( {
			klarna: 'woocommerce_payments_klarna',
		} );
	} );

	it( 'stays generic when more than one provider loses express methods', async () => {
		setDispatch( jest.fn() );

		// Two providers serve their wallets off their own card gateways, and both cards are being
		// consolidated away. Naming them all would not scale, so the copy goes generic.
		const twoAffected: ExpressControlUnit[] = [
			...expressControlUnits,
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
				id: 'other:express',
				providerSlug: 'other-provider',
				providerLabel: 'Other Provider',
				gatewayIds: [ 'other_card' ],
				wallets: [ 'apple_pay' ],
				enabled: true,
				canDisable: true,
				requires: [ 'other_card' ],
			},
		];

		const cardWithTwoOthers: DuplicateResolutionRow = {
			...cardRequiredRow,
			options: [
				...cardRequiredRow.options,
				{
					gatewayId: 'other_card',
					providerSlug: 'other-provider',
					providerLabel: 'Other Provider',
					providerIcon: '',
					canDisable: true,
				},
			],
		};

		render(
			<DuplicateResolutionModal
				rows={ [ cardWithTwoOthers ] }
				expressGroups={ [] }
				expressControlUnits={ twoAffected }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: /Make WooPayments the default/,
			} )
		);

		const notice = document.querySelector(
			'.duplicate-resolution-modal__prerequisite'
		);

		expect( notice?.textContent ).toContain(
			'Apple Pay and Google Pay will also be disabled for other providers'
		);
		// No provider is singled out when several are affected.
		expect( notice?.textContent ).not.toContain( 'disabled for Stripe' );
	} );

	it( 'asks the required-keep question first, on a step of its own', async () => {
		setDispatch( jest.fn() );

		render(
			<DuplicateResolutionModal
				rows={ [ cardRequiredRow, klarnaRow ] }
				expressGroups={ expressGroups }
				expressControlUnits={ expressControlUnits }
				expressWalletLabels={ walletLabels }
				onClose={ jest.fn() }
			/>
		);

		// Step 1 is the opt-in alone: no provider select, and no description above it.
		expect( screen.getByText( 'Step 1 of 3' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', {
				name: /Make WooPayments the default/,
			} )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'combobox' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByText(
				/Choose which provider to use for each payment method/
			)
		).not.toBeInTheDocument();

		// Step 2 is the provider choice, and it does carry the description.
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		expect( screen.getByText( 'Step 2 of 3' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'combobox' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				/Choose which provider to use for each payment method/
			)
		).toBeInTheDocument();
	} );

	it( 'keeps an independently disableable duplicate selectable with no default', () => {
		setDispatch( jest.fn() );

		render(
			<DuplicateResolutionModal
				rows={ [ klarnaRow ] }
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
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

	it( 'submits both the ticked required keep and the chosen free duplicate', async () => {
		const resolve = jest.fn().mockResolvedValue( {
			success: true,
			results: [],
			duplicates: {},
		} );
		setDispatch( resolve );

		render(
			<DuplicateResolutionModal
				rows={ [ cardRequiredRow, klarnaRow ] }
				expressGroups={ [] }
				expressControlUnits={ [] }
				expressWalletLabels={ {} }
				onClose={ jest.fn() }
			/>
		);

		// Step 1: the required-keep opt-in, on its own and shown first.
		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: /Make WooPayments the default/,
			} )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);

		// Step 2: the free-choice duplicate must be answered before applying.
		const apply = screen.getByRole( 'button', { name: 'Apply' } );
		expect( apply ).toBeDisabled();

		await userEvent.selectOptions(
			screen.getByRole( 'combobox' ),
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
