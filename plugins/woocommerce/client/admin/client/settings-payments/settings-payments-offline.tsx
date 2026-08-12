/**
 * External dependencies
 */
import { useSelect } from '@wordpress/data';
import { paymentSettingsStore } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import './settings-payments-offline.scss';
import './settings-payments-body.scss';
import { ListPlaceholder } from '~/settings-payments/components/list-placeholder';
import { OfflinePaymentGatewayList } from '~/settings-payments/components/offline-payment-gateway-list';

/**
 * A component for displaying the list of offline payment gateways in WooCommerce.
 *
 * The list is presentation-only and not reorderable; checkout payment-method ordering (including the
 * offline methods) is controlled on the Payment methods settings page.
 */
export const SettingsPaymentsOffline = () => {
	// Retrieve offline payment gateways and loading state from the store.
	const { offlinePaymentGateways, isFetching } = useSelect( ( select ) => {
		const paymentSettings = select( paymentSettingsStore );
		return {
			offlinePaymentGateways: paymentSettings.getOfflinePaymentGateways(),
			isFetching: paymentSettings.isFetching(),
		};
	}, [] );

	return (
		<>
			{ isFetching ? (
				<ListPlaceholder rows={ 3 } />
			) : (
				<OfflinePaymentGatewayList
					gateways={ offlinePaymentGateways }
				/>
			) }
		</>
	);
};

export default SettingsPaymentsOffline;
