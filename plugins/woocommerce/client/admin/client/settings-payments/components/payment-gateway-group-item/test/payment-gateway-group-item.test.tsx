/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { PaymentGatewayGroupItem } from '../payment-gateway-group-item';
import type { ProviderGroup } from '~/settings-payments/group-providers-by-extension';

jest.mock( '~/settings-payments/components/payment-gateway-list-item', () => ( {
	PaymentGatewayListItem: ( {
		gateway,
		hideSettingsButton,
		hideOfficialBadge,
	}: {
		gateway: { id: string };
		hideSettingsButton?: boolean;
		hideOfficialBadge?: boolean;
	} ) => (
		<div
			data-testid={ `child-${ gateway.id }` }
			data-hide-settings={ String( !! hideSettingsButton ) }
			data-hide-official={ String( !! hideOfficialBadge ) }
		/>
	),
} ) );

jest.mock( '~/settings-payments/components/official-badge', () => ( {
	OfficialBadge: ( { suggestionId }: { suggestionId: string } ) => (
		<div data-testid="official-badge" data-suggestion={ suggestionId } />
	),
} ) );

jest.mock( '~/settings-payments/components/buttons', () => ( {
	SettingsButton: ( { settingsHref }: { settingsHref: string } ) => (
		<button data-testid="parent-manage" data-href={ settingsHref }>
			Manage
		</button>
	),
} ) );

const child = ( id: string ) => ( { id } );

const buildGroup = (
	overrides: Partial< ProviderGroup > = {}
): ProviderGroup =>
	( {
		id: 'paypal',
		title: 'PayPal Payments',
		icon: 'https://example.com/paypal.svg',
		children: [ child( 'ppcp_blik' ), child( 'ppcp_eps' ) ],
		sharedSettingsUrl: undefined,
		...overrides,
	} ) as unknown as ProviderGroup;

const defaultProps = {
	isExpanded: false,
	onToggleExpanded: jest.fn(),
	installingPlugin: null,
	acceptIncentive: jest.fn(),
	shouldHighlightIncentive: false,
	setIsOnboardingModalOpen: jest.fn(),
};

describe( 'PaymentGatewayGroupItem', () => {
	it( 'renders the extension title, icon, and a collapsed chevron toggle', () => {
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup() }
				{ ...defaultProps }
			/>
		);

		expect( screen.getByText( 'PayPal Payments' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'img' ) ).toHaveAttribute(
			'src',
			'https://example.com/paypal.svg'
		);
		const toggle = screen.getByRole( 'button', {
			name: /payment methods for/i,
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'keeps children hidden until expanded', () => {
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup() }
				{ ...defaultProps }
			/>
		);

		expect(
			screen.queryByTestId( 'child-ppcp_blik' )
		).not.toBeInTheDocument();
	} );

	it( 'renders the child gateway rows when expanded', () => {
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup() }
				{ ...defaultProps }
				isExpanded={ true }
			/>
		);

		expect( screen.getByTestId( 'child-ppcp_blik' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'child-ppcp_eps' ) ).toBeInTheDocument();
	} );

	it( 'calls onToggleExpanded when the chevron is clicked', () => {
		const onToggleExpanded = jest.fn();
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup() }
				{ ...defaultProps }
				onToggleExpanded={ onToggleExpanded }
			/>
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: /payment methods for/i } )
		);
		expect( onToggleExpanded ).toHaveBeenCalledTimes( 1 );
	} );

	it( "shows one Manage button on the parent and hides the children's when they share a settings URL", () => {
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup( {
					sharedSettingsUrl: '/settings/paypal',
				} ) }
				{ ...defaultProps }
				isExpanded={ true }
			/>
		);

		const manage = screen.getByTestId( 'parent-manage' );
		expect( manage ).toHaveAttribute( 'data-href', '/settings/paypal' );
		expect( screen.getByTestId( 'child-ppcp_blik' ) ).toHaveAttribute(
			'data-hide-settings',
			'true'
		);
	} );

	it( 'shows the Official badge on the parent and hides it on the children', () => {
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup( { suggestionId: 'paypal' } ) }
				{ ...defaultProps }
				isExpanded={ true }
			/>
		);

		const badge = screen.getByTestId( 'official-badge' );
		expect( badge ).toHaveAttribute( 'data-suggestion', 'paypal' );
		expect( screen.getByTestId( 'child-ppcp_blik' ) ).toHaveAttribute(
			'data-hide-official',
			'true'
		);
	} );

	it( 'shows no Official badge on the parent when the group has no suggestion', () => {
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup( { suggestionId: undefined } ) }
				{ ...defaultProps }
			/>
		);

		expect(
			screen.queryByTestId( 'official-badge' )
		).not.toBeInTheDocument();
	} );

	it( 'shows no parent Manage and lets children keep theirs when settings URLs differ', () => {
		render(
			<PaymentGatewayGroupItem
				group={ buildGroup( { sharedSettingsUrl: undefined } ) }
				{ ...defaultProps }
				isExpanded={ true }
			/>
		);

		expect(
			screen.queryByTestId( 'parent-manage' )
		).not.toBeInTheDocument();
		expect( screen.getByTestId( 'child-ppcp_blik' ) ).toHaveAttribute(
			'data-hide-settings',
			'false'
		);
	} );
} );
