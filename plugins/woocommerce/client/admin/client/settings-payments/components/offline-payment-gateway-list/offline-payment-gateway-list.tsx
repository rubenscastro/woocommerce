/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { type OfflinePaymentMethodProvider } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import sanitizeHTML from '~/lib/sanitize-html';
import {
	EnableGatewayButton,
	SettingsButton,
} from '~/settings-payments/components/buttons';
import './offline-payment-gateway-list.scss';

type OfflinePaymentGatewayListItemProps = {
	/**
	 * The offline payment gateway to display in the list item.
	 */
	gateway: OfflinePaymentMethodProvider;
	className?: string;
};

/**
 * A component that renders an offline payment gateway as a list item.
 * Displays gateway information including the title, description, icon, and actions to enable or manage the gateway.
 */
export const OfflinePaymentGatewayListItem = ( {
	gateway,
	...props
}: OfflinePaymentGatewayListItemProps ) => {
	return (
		<div
			id={ gateway.id }
			className={
				'woocommerce-list__item woocommerce-list__item-enter-done' +
				( props.className ? ` ${ props.className }` : '' )
			}
		>
			<div className="woocommerce-list__item-inner">
				{ /* Left section with icon */ }
				<div className="woocommerce-list__item-before">
					{ gateway.icon && (
						<img
							className={ 'woocommerce-list__item-image' }
							src={ gateway.icon }
							alt={ gateway.title + ' logo' }
						/>
					) }
				</div>
				{ /* Middle section with title and description */ }
				<div className="woocommerce-list__item-text">
					<span className="woocommerce-list__item-title">
						{ gateway.title }
					</span>
					<span
						className="woocommerce-list__item-content"
						dangerouslySetInnerHTML={ sanitizeHTML(
							decodeEntities( gateway.description )
						) }
					/>
				</div>
				{ /* Right section with action buttons */ }
				<div className="woocommerce-list__item-after">
					<div className="woocommerce-list__item-after__actions">
						{ ! gateway.state.enabled ? (
							<EnableGatewayButton
								installingPlugin={ null }
								gatewayProvider={ gateway }
								settingsHref={
									gateway.management._links.settings.href
								}
								onboardingHref={
									gateway.onboarding._links.onboard.href
								}
								isOffline={ true }
								gatewayHasRecommendedPaymentMethods={ false } // Offline gateway items don't have recommended PMs.
							/>
						) : (
							<SettingsButton
								gatewayProvider={ gateway }
								settingsHref={
									gateway.management._links.settings.href
								}
								isInstallingPlugin={ false }
							/>
						) }
					</div>
				</div>
			</div>
		</div>
	);
};

/**
 * A component that renders the list of offline payment gateways.
 * Each gateway is rendered as an `OfflinePaymentGatewayListItem`. The list is presentation-only and
 * not reorderable; offline method ordering at checkout is controlled on the Payment methods page.
 */
export const OfflinePaymentGatewayList = ( {
	gateways,
}: {
	gateways: OfflinePaymentMethodProvider[];
} ) => {
	return (
		<div className="woocommerce-list">
			{ gateways.map( ( method, index ) => (
				<OfflinePaymentGatewayListItem
					gateway={ method }
					key={ method.id }
					className={
						'woocommerce-list__item' +
						( index === gateways.length - 1 ? ' is-last' : '' )
					}
				/>
			) ) }
		</div>
	);
};
