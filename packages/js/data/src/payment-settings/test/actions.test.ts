/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { updatePaymentMethodOrder } from '../actions';
import { WC_ADMIN_NAMESPACE } from '../../constants';

jest.mock( '@wordpress/api-fetch' );

const ENDPOINT =
	WC_ADMIN_NAMESPACE + '/settings/payments/payment-methods/order';

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
} );
