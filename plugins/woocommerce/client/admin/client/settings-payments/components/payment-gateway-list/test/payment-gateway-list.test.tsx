/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';
import type { PaymentsProvider } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { PaymentGatewayList } from '../payment-gateway-list';
import type { ProviderGroup } from '~/settings-payments/group-providers-by-extension';

const recordPaymentsEvent = jest.fn();

jest.mock( 'react-router-dom', () => ( {
	useNavigate: () => jest.fn(),
} ) );

// Avoid loading the real @woocommerce/data (and @wordpress/core-data) in the test environment;
// provide only the runtime values the list and the grouping helper read.
jest.mock( '@woocommerce/data', () => ( {
	paymentSettingsStore: 'payment-settings-store',
	PaymentsProviderType: {
		Suggestion: 'suggestion',
		Gateway: 'gateway',
		OfflinePmsGroup: 'offline_pms_group',
		OfflinePm: 'offline_pm',
	},
} ) );

// Keep the real @wordpress/data (other modules register stores at import time) and override only
// useSelect to return the PayPal suggestion, so the group gets its name.
jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: ( mapper: ( select: unknown ) => unknown ) =>
		mapper( () => ( {
			getSuggestions: () => [
				{ id: 'paypal', title: 'PayPal Payments', icon: 'x' },
			],
		} ) ),
} ) );

jest.mock( '~/settings-payments/utils', () => ( {
	recordPaymentsEvent: ( ...args: unknown[] ) =>
		recordPaymentsEvent( ...args ),
	removeOriginFromURL: ( url: string ) => url,
} ) );

jest.mock(
	'~/settings-payments/components/payment-extension-suggestion-list-item',
	() => ( {
		PaymentExtensionSuggestionListItem: () => null,
	} )
);

jest.mock( '~/settings-payments/components/payment-gateway-list-item', () => ( {
	PaymentGatewayListItem: ( { gateway }: { gateway: { id: string } } ) => (
		<div data-testid={ `single-${ gateway.id }` } />
	),
} ) );

// Stub the group item with a button that surfaces the expansion props so the list's state and
// analytics wiring can be asserted in isolation.
jest.mock(
	'~/settings-payments/components/payment-gateway-group-item',
	() => ( {
		PaymentGatewayGroupItem: ( {
			group,
			isExpanded,
			onToggleExpanded,
		}: {
			group: ProviderGroup;
			isExpanded: boolean;
			onToggleExpanded: () => void;
		} ) => (
			<button
				data-testid={ `group-${ group.id }` }
				data-expanded={ String( isExpanded ) }
				data-child-count={ group.children.length }
				onClick={ onToggleExpanded }
			>
				{ group.title }
			</button>
		),
	} )
);

const gateway = ( id: string, suggestionId: string ): PaymentsProvider =>
	( {
		id,
		_type: 'gateway',
		_order: 0,
		title: id,
		_suggestion_id: suggestionId,
		plugin: {
			slug: 'woocommerce-paypal-payments',
			file: '',
			status: 'active',
		},
		management: { _links: { settings: { href: `/s/${ id }` } } },
	} ) as unknown as PaymentsProvider;

const defaultProps = {
	installedPluginSlugs: [],
	installingPlugin: null,
	setUpPlugin: jest.fn(),
	acceptIncentive: jest.fn(),
	shouldHighlightIncentive: false,
	setIsOnboardingModalOpen: jest.fn(),
};

describe( 'PaymentGatewayList', () => {
	beforeEach( () => {
		recordPaymentsEvent.mockClear();
	} );

	it( 'groups same-extension gateways into one collapsed group and leaves single gateways alone', () => {
		render(
			<PaymentGatewayList
				providers={ [
					gateway( 'ppcp_blik', 'paypal' ),
					gateway( 'ppcp_eps', 'paypal' ),
					gateway( 'stripe', 'stripe' ),
				] }
				{ ...defaultProps }
			/>
		);

		const group = screen.getByTestId( 'group-paypal' );
		expect( group ).toHaveTextContent( 'PayPal Payments' );
		expect( group ).toHaveAttribute( 'data-child-count', '2' );
		expect( group ).toHaveAttribute( 'data-expanded', 'false' );
		expect( screen.getByTestId( 'single-stripe' ) ).toBeInTheDocument();
	} );

	it( 'toggles a group and records the analytics event', () => {
		render(
			<PaymentGatewayList
				providers={ [
					gateway( 'ppcp_blik', 'paypal' ),
					gateway( 'ppcp_eps', 'paypal' ),
				] }
				{ ...defaultProps }
			/>
		);

		fireEvent.click( screen.getByTestId( 'group-paypal' ) );

		expect( screen.getByTestId( 'group-paypal' ) ).toHaveAttribute(
			'data-expanded',
			'true'
		);
		expect( recordPaymentsEvent ).toHaveBeenCalledWith(
			'provider_extension_group_toggle',
			{ extension_group: 'paypal', action: 'expand' }
		);
	} );
} );
