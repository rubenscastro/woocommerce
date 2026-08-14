/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	updatePaymentMethodOrder,
	resolvePaymentMethodDuplicates,
} from '../actions';
import { WC_ADMIN_NAMESPACE } from '../../constants';

jest.mock( '@wordpress/api-fetch' );

const ENDPOINT =
	WC_ADMIN_NAMESPACE + '/settings/payments/payment-methods/order';

const RESOLVE_ENDPOINT =
	WC_ADMIN_NAMESPACE +
	'/settings/payments/payment-methods/duplicates/resolve';

describe( 'payment-settings payment-method order actions', () => {
	beforeEach( () => {
		( apiFetch as unknown as jest.Mock ).mockReset();
	} );

	describe( 'updatePaymentMethodOrder', () => {
		it( 'should POST the order to the payment-methods order endpoint and return the result', () => {
			( apiFetch as unknown as jest.Mock ).mockReturnValue( undefined );

			const generator = updatePaymentMethodOrder( [ 'gw_c', 'gw_a' ] );

			generator.next();

			expect( apiFetch ).toHaveBeenCalledWith( {
				path: ENDPOINT,
				method: 'POST',
				data: { order: [ 'gw_c', 'gw_a' ] },
			} );

			const result = generator.next( { success: true } );

			expect( result.value ).toEqual( { success: true } );
			expect( result.done ).toBe( true );
		} );
	} );

	describe( 'resolvePaymentMethodDuplicates', () => {
		it( 'should POST the selections to the resolve endpoint and return the report', () => {
			( apiFetch as unknown as jest.Mock ).mockReturnValue( undefined );

			const generator = resolvePaymentMethodDuplicates( {
				klarna: 'woocommerce_payments_klarna',
			} );

			generator.next();

			expect( apiFetch ).toHaveBeenCalledWith( {
				path: RESOLVE_ENDPOINT,
				method: 'POST',
				data: {
					selections: { klarna: 'woocommerce_payments_klarna' },
				},
			} );

			const report = {
				success: true,
				results: [],
				duplicates: { payment_methods: {}, express: {} },
			};
			const result = generator.next( report );

			expect( result.value ).toEqual( report );
			expect( result.done ).toBe( true );
		} );
	} );
} );
