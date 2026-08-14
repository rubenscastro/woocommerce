/**
 * External dependencies
 */
import {
	PaymentsEntity,
	PaymentsProvider,
	PaymentsProviderType,
	PaymentGatewayProvider,
	OfflinePmsGroupProvider,
	PaymentsExtensionSuggestionProvider,
	SuggestedPaymentsExtension,
	paymentSettingsStore,
} from '@woocommerce/data';
import { Gridicon } from '@automattic/components';
import { Fragment, useMemo, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useNavigate } from 'react-router-dom';
import { isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PaymentExtensionSuggestionListItem } from '~/settings-payments/components/payment-extension-suggestion-list-item';
import { PaymentGatewayListItem } from '~/settings-payments/components/payment-gateway-list-item';
import { PaymentGatewayGroupItem } from '~/settings-payments/components/payment-gateway-group-item';
import './payment-gateway-list.scss';
import {
	recordPaymentsEvent,
	removeOriginFromURL,
} from '~/settings-payments/utils';
import {
	groupProvidersByExtension,
	type ProviderGroup,
} from '~/settings-payments/group-providers-by-extension';

/**
 * Whether a gateway still needs setup — its account is not connected, or onboarding was never started
 * or never completed, or it is disabled and flagged as needing setup.
 *
 * Mirrors the `needs_setup`/onboarding logic `PaymentGatewayListItem` uses to pick a row status, read
 * from the same provider state fields, so "needs setup" means the same thing here as on the row.
 */
const gatewayNeedsSetup = ( gateway: PaymentGatewayProvider ): boolean => {
	const state = gateway.state;

	// Without state we cannot tell whether setup is complete, so stay conservative and do not
	// force-expand on unknown data.
	if ( ! state ) {
		return false;
	}

	const connected = state.account_connected;
	const started = gateway.onboarding?.state?.started;
	const completed = gateway.onboarding?.state?.completed;

	const needsOnboarding =
		! connected ||
		( connected && ! started ) ||
		( connected && started && ! completed );

	return needsOnboarding || ( ! state.enabled && state.needs_setup );
};

/**
 * Whether a grouped provider should be expanded by default.
 *
 * A provider that is installed (its gateways are registered), has child payment-method rows (a group
 * always does), and has not finished setup is expanded so the merchant can see what still needs
 * attention. Fully configured providers are left at the default collapsed state. Derived from live
 * provider state, never from provider names.
 */
const groupExpandedByDefault = ( group: ProviderGroup ): boolean =>
	group.children.some( gatewayNeedsSetup );

interface PaymentGatewayListProps {
	/**
	 * List of payments providers to display.
	 */
	providers: PaymentsProvider[];
	/**
	 * Array of slugs for installed plugins.
	 */
	installedPluginSlugs: string[];
	/**
	 * The ID of the plugin currently being installed, or `null` if none.
	 */
	installingPlugin: string | null;
	/**
	 * Callback to set up the plugin.
	 *
	 * @param provider      Extension provider.
	 * @param onboardingUrl Extension onboarding URL (if available).
	 * @param attachUrl     Extension attach URL (if available).
	 * @param context       The context from which the plugin is set up (e.g. 'wc_settings_payments__main_suggestion').
	 */
	setUpPlugin: (
		provider: PaymentsEntity,
		onboardingUrl: string | null,
		attachUrl: string | null,
		context?: string
	) => void;
	/**
	 * Callback to handle accepting an incentive. Receives the incentive ID as a parameter.
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
 * A component that renders the list of payment providers. Depending on the provider type, it displays
 * different components such as `PaymentExtensionSuggestionListItem`, `PaymentGatewayListItem`, or a custom
 * clickable item for offline payment groups.
 *
 * The list is presentation-only: its order reflects the native provider registration order and is not
 * reorderable. Checkout payment-method ordering is controlled on the Payment methods settings page.
 */
export const PaymentGatewayList = ( {
	providers,
	installedPluginSlugs,
	installingPlugin,
	setUpPlugin,
	acceptIncentive,
	shouldHighlightIncentive,
	setIsOnboardingModalOpen,
}: PaymentGatewayListProps ) => {
	const navigate = useNavigate();

	// Extensions that register several gateways (PayPal, Square, Amazon Pay, …) show up as separate
	// rows; group those rows under one extension parent. The parent's name/icon come from the matching
	// payment-extension suggestion, so read the suggestions once and key them by id for the lookup.
	const suggestions = useSelect(
		( select ) => select( paymentSettingsStore ).getSuggestions(),
		[]
	);
	const suggestionsById = useMemo(
		() =>
			( suggestions ?? [] ).reduce<
				Record< string, SuggestedPaymentsExtension >
			>( ( map, suggestion ) => {
				map[ suggestion.id ] = suggestion;
				return map;
			}, {} ),
		[ suggestions ]
	);

	const items = useMemo(
		() => groupProvidersByExtension( providers, suggestionsById ),
		[ providers, suggestionsById ]
	);

	// The merchant's explicit expand/collapse choices, keyed by group id. UI-only state; nothing is
	// persisted. A group with no entry here falls back to its data-derived default (expanded when it
	// still needs setup), so the default stays correct even as provider data loads in asynchronously.
	const [ expandedOverrides, setExpandedOverrides ] = useState<
		Record< string, boolean >
	>( {} );

	const isGroupExpanded = ( group: ProviderGroup ): boolean =>
		expandedOverrides[ group.id ] ?? groupExpandedByDefault( group );

	const toggleGroupExpanded = ( group: ProviderGroup ) => {
		const next = ! isGroupExpanded( group );

		recordPaymentsEvent( 'provider_extension_group_toggle', {
			extension_group: group.id,
			action: next ? 'expand' : 'collapse',
		} );

		setExpandedOverrides( ( previous ) => ( {
			...previous,
			[ group.id ]: next,
		} ) );
	};

	const renderProvider = ( provider: PaymentsProvider ) => {
		switch ( provider._type ) {
			case PaymentsProviderType.Suggestion:
				const suggestion =
					provider as PaymentsExtensionSuggestionProvider;
				const pluginInstalled = installedPluginSlugs.includes(
					provider.plugin.slug
				);
				return (
					<Fragment key={ suggestion.id }>
						{ PaymentExtensionSuggestionListItem( {
							suggestion,
							installingPlugin,
							setUpPlugin,
							pluginInstalled,
							acceptIncentive,
							shouldHighlightIncentive,
						} ) }
					</Fragment>
				);
			case PaymentsProviderType.Gateway:
				const gateway = provider as PaymentGatewayProvider;
				return (
					<Fragment key={ provider.id }>
						{ PaymentGatewayListItem( {
							gateway,
							installingPlugin,
							acceptIncentive,
							shouldHighlightIncentive,
							setIsOnboardingModalOpen,
						} ) }
					</Fragment>
				);
			case PaymentsProviderType.OfflinePmsGroup:
				const offlinePmsGroup = provider as OfflinePmsGroupProvider;
				return (
					// eslint-disable-next-line jsx-a11y/click-events-have-key-events,jsx-a11y/no-static-element-interactions
					<div
						key={ offlinePmsGroup.id }
						id={ offlinePmsGroup.id }
						className="transitions-disabled woocommerce-list__item clickable-list-item enter-done"
						onClick={ () => {
							navigate(
								removeOriginFromURL(
									offlinePmsGroup.management._links.settings
										.href
								)
							);
						} }
					>
						<div className="woocommerce-list__item-inner">
							<div className="woocommerce-list__item-before">
								<img
									src={ offlinePmsGroup.icon }
									alt={ offlinePmsGroup.title + ' logo' }
								/>
							</div>
							<div className="woocommerce-list__item-text">
								<span className="woocommerce-list__item-title">
									{ offlinePmsGroup.title }
								</span>
								<span
									className="woocommerce-list__item-content"
									dangerouslySetInnerHTML={ {
										__html: offlinePmsGroup.description,
									} }
								/>
							</div>
							<div className="woocommerce-list__item-after centered no-buttons">
								<div className="woocommerce-list__item-after__actions">
									<a
										className="woocommerce-list__item-after__actions__arrow"
										href={
											offlinePmsGroup.management._links
												.settings.href
										}
										aria-label={ offlinePmsGroup.title }
									>
										<Gridicon
											icon={
												isRTL()
													? 'chevron-left'
													: 'chevron-right'
											}
										/>
									</a>
								</div>
							</div>
						</div>
					</div>
				);
			default:
				return null;
		}
	};

	return (
		<div className="settings-payment-gateways__list">
			{ items.map( ( item ) =>
				item.type === 'group' ? (
					<PaymentGatewayGroupItem
						key={ item.group.id }
						group={ item.group }
						isExpanded={ isGroupExpanded( item.group ) }
						onToggleExpanded={ () =>
							toggleGroupExpanded( item.group )
						}
						installingPlugin={ installingPlugin }
						acceptIncentive={ acceptIncentive }
						shouldHighlightIncentive={ shouldHighlightIncentive }
						setIsOnboardingModalOpen={ setIsOnboardingModalOpen }
					/>
				) : (
					<Fragment key={ item.provider.id }>
						{ renderProvider( item.provider ) }
					</Fragment>
				)
			) }
		</div>
	);
};
