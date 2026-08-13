/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { Gridicon } from '@automattic/components';

/**
 * Internal dependencies
 */
import { PaymentGatewayListItem } from '~/settings-payments/components/payment-gateway-list-item';
import { SettingsButton } from '~/settings-payments/components/buttons';
import { OfficialBadge } from '~/settings-payments/components/official-badge';
import type { ProviderGroup } from '~/settings-payments/group-providers-by-extension';

interface PaymentGatewayGroupItemProps {
	/**
	 * The extension group to render: a parent header row and its nested gateway rows.
	 */
	group: ProviderGroup;
	/**
	 * Whether the group's children are currently disclosed.
	 */
	isExpanded: boolean;
	/**
	 * Toggle the disclosure of the group's children.
	 */
	onToggleExpanded: () => void;
	/**
	 * The ID of the plugin currently being installed, or `null` if none.
	 */
	installingPlugin: string | null;
	/**
	 * Callback to handle accepting an incentive.
	 */
	acceptIncentive: ( id: string ) => void;
	/**
	 * Indicates whether the incentive should be highlighted.
	 */
	shouldHighlightIncentive: boolean;
	/**
	 * Callback to open or close the onboarding modal.
	 */
	setIsOnboardingModalOpen: ( isOpen: boolean ) => void;
}

/**
 * A parent row for an extension that registered several payment gateways, with those gateways nested
 * beneath it. Presentation-only: the grouping reflects shared ownership and never changes ordering or
 * any persisted state.
 *
 * When every child points at the same settings page, the parent shows one Manage button and the
 * children hide theirs; otherwise each child keeps its own Manage button.
 */
export const PaymentGatewayGroupItem = ( {
	group,
	isExpanded,
	onToggleExpanded,
	installingPlugin,
	acceptIncentive,
	shouldHighlightIncentive,
	setIsOnboardingModalOpen,
}: PaymentGatewayGroupItemProps ) => {
	const childrenId = `settings-payment-gateways-extension-${ group.id }`;

	return (
		<div className="settings-payment-gateways__extension-group">
			<div className="woocommerce-list__item settings-payment-gateways__extension-group-header">
				<div className="woocommerce-list__item-inner">
					<div className="woocommerce-list__item-before">
						{ group.icon && (
							<img
								className="woocommerce-list__item-image"
								src={ group.icon }
								alt={ sprintf(
									// translators: %s: the payment extension's name.
									__( '%s logo', 'woocommerce' ),
									group.title
								) }
							/>
						) }
					</div>
					<div className="woocommerce-list__item-text">
						<span className="woocommerce-list__item-title">
							{ group.title }
							{ group.suggestionId && (
								<OfficialBadge
									variant="expanded"
									suggestionId={ group.suggestionId }
								/>
							) }
						</span>
					</div>
					<div className="woocommerce-list__item-after no-buttons">
						<div className="woocommerce-list__item-after__actions">
							{ group.sharedSettingsUrl && (
								<SettingsButton
									gatewayProvider={ group.children[ 0 ] }
									settingsHref={ group.sharedSettingsUrl }
									isInstallingPlugin={ !! installingPlugin }
								/>
							) }
							<Button
								className="settings-payment-gateways__expander"
								aria-expanded={ isExpanded }
								aria-controls={ childrenId }
								aria-label={
									isExpanded
										? sprintf(
												// translators: %s: the payment extension's name.
												__(
													'Hide payment methods for %s',
													'woocommerce'
												),
												group.title
										  )
										: sprintf(
												// translators: %s: the payment extension's name.
												__(
													'Show payment methods for %s',
													'woocommerce'
												),
												group.title
										  )
								}
								onClick={ onToggleExpanded }
							>
								<Gridicon
									icon={
										isExpanded
											? 'chevron-up'
											: 'chevron-down'
									}
								/>
							</Button>
						</div>
					</div>
				</div>
			</div>
			{ isExpanded && (
				<div
					id={ childrenId }
					className="settings-payment-gateways__extension-group-children"
				>
					{ group.children.map( ( child ) => (
						<Fragment key={ child.id }>
							{ PaymentGatewayListItem( {
								gateway: child,
								installingPlugin,
								acceptIncentive,
								shouldHighlightIncentive,
								setIsOnboardingModalOpen,
								hideSettingsButton: !! group.sharedSettingsUrl,
								hideOfficialBadge: true,
							} ) }
						</Fragment>
					) ) }
				</div>
			) }
		</div>
	);
};
