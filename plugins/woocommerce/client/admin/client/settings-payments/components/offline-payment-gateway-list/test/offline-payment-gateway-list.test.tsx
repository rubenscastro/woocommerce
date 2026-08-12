/**
 * External dependencies
 */
import { render } from '@testing-library/react';
import type { OfflinePaymentMethodProvider } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { OfflinePaymentGatewayList } from '../offline-payment-gateway-list';

jest.mock( '~/settings-payments/components/buttons', () => ( {
	EnableGatewayButton: () => <button>Enable</button>,
	SettingsButton: () => <button>Manage</button>,
} ) );

const createGateway = (
	id: string,
	overrides: Partial< OfflinePaymentMethodProvider > = {}
): OfflinePaymentMethodProvider =>
	( {
		id,
		_order: 0,
		title: `Gateway ${ id }`,
		description: 'Description',
		icon: 'https://example.com/icon.svg',
		state: { enabled: false },
		management: { _links: { settings: { href: '#' } } },
		onboarding: { _links: { onboard: { href: '#' } } },
		...overrides,
	} ) as unknown as OfflinePaymentMethodProvider;

describe( 'OfflinePaymentGatewayList', () => {
	it( 'renders each offline gateway', () => {
		const { getByText } = render(
			<OfflinePaymentGatewayList
				gateways={ [ createGateway( 'bacs' ), createGateway( 'cod' ) ] }
			/>
		);

		expect( getByText( 'Gateway bacs' ) ).toBeInTheDocument();
		expect( getByText( 'Gateway cod' ) ).toBeInTheDocument();
	} );

	it( 'is presentation-only: renders no drag handles', () => {
		const { container } = render(
			<OfflinePaymentGatewayList
				gateways={ [ createGateway( 'bacs' ) ] }
			/>
		);

		expect(
			container.querySelector( '.drag-handle' )
		).not.toBeInTheDocument();
	} );
} );
