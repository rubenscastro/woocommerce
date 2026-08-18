/**
 * External dependencies
 */
import { Notice, SelectControl } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	calculateExpressImpact,
	expandGroupSelections,
} from './express-impact';
import type {
	ExpressControlUnit,
	ExpressDuplicateGroup,
	ExpressProviderOption,
} from './types';

interface StepExpressProps {
	/**
	 * One entry per decision about duplicated express methods.
	 */
	groups: ExpressDuplicateGroup[];
	/**
	 * The full control-unit graph, used to work out what a choice would turn off.
	 */
	controlUnits: ExpressControlUnit[];
	/**
	 * Readable labels for every method in the graph, including ones with no decision of their own.
	 */
	walletLabels: Record< string, string >;
	/**
	 * The currently chosen provider per group id (empty when not yet chosen).
	 */
	selections: Record< string, string >;
	/**
	 * Record a provider choice for a group. An empty value clears the choice.
	 */
	onSelect: ( groupId: string, providerSlug: string ) => void;
}

/**
 * The readable name of an express method.
 */
const methodNameOf = (
	walletId: string,
	walletLabels: Record< string, string >
): string => walletLabels[ walletId ] ?? walletId;

/**
 * The methods a chosen provider would not carry over, split by whether the merchant can keep them.
 *
 * A provider that offers a method but has it switched off loses it to this choice just as surely as
 * one that never offered it. The difference is what the merchant can do next: the first is
 * recoverable by enabling the method in that provider's own settings, the second is not recoverable
 * at all. Calling both "does not offer" states something untrue about the provider and dead-ends a
 * merchant who still has a way to keep the method, so the two are kept apart all the way to the copy.
 */
const losesFrom = (
	group: ExpressDuplicateGroup,
	option: ExpressProviderOption,
	walletLabels: Record< string, string >
): { switchedOff: string[]; notOffered: string[] } => {
	const uncovered = group.walletIds.filter(
		( walletId ) => ! option.covers.includes( walletId )
	);
	const names = ( walletIds: string[] ) =>
		walletIds.map( ( walletId ) => methodNameOf( walletId, walletLabels ) );

	return {
		switchedOff: names(
			uncovered.filter( ( walletId ) =>
				option.supportsDisabled.includes( walletId )
			)
		),
		notOffered: names(
			uncovered.filter(
				( walletId ) => ! option.supportsDisabled.includes( walletId )
			)
		),
	};
};

/**
 * Join names into a readable list.
 *
 * Built from a whole phrase rather than by joining on a translated fragment, so the spacing cannot
 * be lost to trimming and translators get something they can reorder.
 */
const joinNames = ( names: string[] ): string => {
	if ( names.length < 2 ) {
		return names[ 0 ] ?? '';
	}

	return sprintf(
		/* translators: 1: all express method names except the last, comma separated. 2: the last express method name. */
		__( '%1$s and %2$s', 'woocommerce' ),
		names.slice( 0, -1 ).join( ', ' ),
		names[ names.length - 1 ]
	);
};

/**
 * Step 2 of the resolution modal: express checkout methods.
 *
 * One decision per group. Methods that a single provider setting controls together are decided
 * together, because they cannot be given to different providers — offering them separately would
 * invite a combination that cannot exist, and then refuse it.
 *
 * A provider that carries only part of a group is still a valid choice; the rest of the group is
 * disabled as a result, and the notice says so before the merchant commits — separating the part
 * that is simply gone from the part the merchant can still keep by enabling it in that provider's
 * own settings.
 */
export const StepExpress = ( {
	groups,
	controlUnits,
	walletLabels,
	selections,
	onSelect,
}: StepExpressProps ) => {
	const impact = useMemo(
		() =>
			calculateExpressImpact(
				controlUnits,
				expandGroupSelections( groups, selections )
			),
		[ controlUnits, groups, selections ]
	);

	return (
		<div className="duplicate-resolution-modal__express">
			<p className="duplicate-resolution-modal__description">
				{ __(
					'Choose which provider should offer each express checkout method.',
					'woocommerce'
				) }
			</p>

			<div className="duplicate-resolution-modal__list">
				{ groups.map( ( group ) => {
					const chosen = group.options.find(
						( option ) =>
							option.providerSlug === selections[ group.id ]
					);
					const lost = chosen
						? losesFrom( group, chosen, walletLabels )
						: { switchedOff: [], notOffered: [] };

					return (
						<div
							key={ group.id }
							className="duplicate-resolution-modal__express-group"
						>
							<div className="duplicate-resolution-modal__express-icons">
								{ group.icons.map( ( icon ) => (
									<img key={ icon } src={ icon } alt="" />
								) ) }
							</div>
							<span className="duplicate-resolution-modal__row-title">
								{ group.label }
							</span>
							<SelectControl
								__nextHasNoMarginBottom
								className="duplicate-resolution-modal__row-select"
								aria-label={ sprintf(
									/* translators: %s: express checkout method name(s), e.g. "Apple Pay and Google Pay". */
									__(
										'Choose a provider for %s',
										'woocommerce'
									),
									group.label
								) }
								value={ selections[ group.id ] ?? '' }
								options={ [
									{
										label: __(
											'Choose a provider',
											'woocommerce'
										),
										value: '',
									},
									...group.options.map( ( option ) => ( {
										label: option.providerLabel,
										value: option.providerSlug,
									} ) ),
								] }
								onChange={ ( value ) =>
									onSelect( group.id, value )
								}
							/>

							{ lost.switchedOff.length > 0 && (
								<Notice
									className="duplicate-resolution-modal__express-consequence"
									status="warning"
									isDismissible={ false }
								>
									<strong>
										{ sprintf(
											/* translators: %s: express checkout method name(s) that will be turned off. */
											__(
												'%s will also be disabled',
												'woocommerce'
											),
											joinNames( lost.switchedOff )
										) }
									</strong>
									<p>
										{ sprintf(
											/* translators: 1: the chosen provider, 2: the express methods it offers but has switched off. */
											__(
												'%1$s offers %2$s, but it is turned off. Enable it in %1$s settings to keep offering it.',
												'woocommerce'
											),
											chosen?.providerLabel ?? '',
											joinNames( lost.switchedOff )
										) }
									</p>
								</Notice>
							) }

							{ lost.notOffered.length > 0 && (
								<Notice
									className="duplicate-resolution-modal__express-consequence"
									status="warning"
									isDismissible={ false }
								>
									<strong>
										{ sprintf(
											/* translators: %s: express checkout method name(s) that will be turned off. */
											__(
												'%s will also be disabled',
												'woocommerce'
											),
											joinNames( lost.notOffered )
										) }
									</strong>
									<p>
										{ sprintf(
											/* translators: 1: the chosen provider, 2: the express methods it does not offer. */
											__(
												'%1$s does not offer %2$s, so choosing it here will turn it off.',
												'woocommerce'
											),
											chosen?.providerLabel ?? '',
											joinNames( lost.notOffered )
										) }
									</p>
								</Notice>
							) }
						</div>
					);
				} ) }
			</div>

			{ impact.blocked.length > 0 && (
				<Notice
					className="duplicate-resolution-modal__express-blocked"
					status="error"
					isDismissible={ false }
				>
					{ __(
						'One of the providers cannot be turned off individually, so this choice cannot be applied.',
						'woocommerce'
					) }
				</Notice>
			) }
		</div>
	);
};
