/**
 * External dependencies
 */
import {
	Card as WPCard,
	CardBody,
	Notice,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { calculatePrerequisiteImpact } from './express-impact';
import type { DuplicateResolutionRow, ExpressControlUnit } from './types';

interface StepRegularProps {
	/**
	 * One row per resolvable regular duplicate.
	 */
	rows: DuplicateResolutionRow[];
	/**
	 * The currently chosen gateway id per canonical method (empty when not yet chosen).
	 */
	selections: Record< string, string >;
	/**
	 * Record a provider choice for a canonical method. An empty gateway id clears the choice.
	 */
	onSelect: ( canonicalId: string, gatewayId: string ) => void;
	/**
	 * The express control-unit graph, used to warn when keeping one provider for a regular method
	 * would take another provider's express methods down with it.
	 */
	controlUnits?: ExpressControlUnit[];
	/**
	 * Readable labels for express methods.
	 */
	walletLabels?: Record< string, string >;
}

/**
 * Join names into a readable list.
 */
const joinNames = ( names: string[] ): string => {
	if ( names.length < 2 ) {
		return names[ 0 ] ?? '';
	}

	return sprintf(
		/* translators: 1: all names except the last, comma separated. 2: the last name. */
		__( '%1$s and %2$s', 'woocommerce' ),
		names.slice( 0, -1 ).join( ', ' ),
		names[ names.length - 1 ]
	);
};

/**
 * The method's readable name. Row labels are React nodes, but for the server-built rows they are the
 * plain method-label string; fall back to the canonical id otherwise.
 */
const methodNameOf = ( row: DuplicateResolutionRow ): string =>
	typeof row.label === 'string' && row.label ? row.label : row.canonicalId;

/**
 * The provider label for a gateway id within a row's options.
 */
const providerLabelOf = ( row: DuplicateResolutionRow, gatewayId: string ) =>
	row.options.find( ( option ) => option.gatewayId === gatewayId )
		?.providerLabel ?? gatewayId;

/**
 * Step 1 of the resolution modal: choose which provider to keep for each duplicated regular method.
 *
 * When one implementation cannot be disabled (e.g. WooPayments Card is mandatory while WooPayments is
 * active) the choice is not "which provider" but "do you want that provider to own this method and hide
 * the duplicates?" — so that row renders an opt-in checkbox instead of a locked select. Ticking it keeps
 * the required provider and disables the others; leaving it unticked resolves nothing for that method.
 */
export const StepRegular = ( {
	rows,
	selections,
	onSelect,
	controlUnits = [],
	walletLabels = {},
}: StepRegularProps ) => (
	<div className="duplicate-resolution-modal__list">
		{ rows.map( ( row ) => {
			const requiredKeep = row.requiredKeepGatewayId;
			const method = methodNameOf( row );

			// A required-keep method (e.g. Card) is a single primary decision, so it is set apart in
			// its own card container with an opt-in toggle rather than a row with a select.
			if ( requiredKeep ) {
				const isOn = selections[ row.canonicalId ] === requiredKeep;

				// Turning this on disables every other provider's implementation of this method —
				// and a provider that serves its express methods off the back of it loses those too.
				const toDisable = isOn
					? row.options
							.filter(
								( option ) => option.gatewayId !== requiredKeep
							)
							.map( ( option ) => option.gatewayId )
					: [];
				const knockOn = calculatePrerequisiteImpact(
					controlUnits,
					toDisable
				);
				const affectedNames = [
					...knockOn.lostMethods,
					...knockOn.survivingMethods,
				].map( ( walletId ) => walletLabels[ walletId ] ?? walletId );

				// Counted by provider, not by unit: one provider can own several units, and losing
				// two of PayPal's units is still only PayPal.
				const affectedProviders = [
					...new Set(
						knockOn.brokenUnits.map(
							( unit ) => unit.providerLabel
						)
					),
				];

				return (
					<WPCard
						key={ row.canonicalId }
						size="small"
						className="duplicate-resolution-modal__required"
					>
						<CardBody>
							<ToggleControl
								__nextHasNoMarginBottom
								className="duplicate-resolution-modal__row-toggle"
								label={ sprintf(
									/* translators: 1: provider name, 2: payment method name. */
									__(
										'Make %1$s the default %2$s provider',
										'woocommerce'
									),
									providerLabelOf( row, requiredKeep ),
									method
								) }
								help={ sprintf(
									/* translators: 1: payment method name, 2: number of providers, 3: provider name, 4: payment method name. */
									__(
										'%1$s payments are currently available through %2$d providers. Make %3$s the primary option and hide duplicate %4$s payment methods from other providers.',
										'woocommerce'
									),
									method,
									row.options.length,
									providerLabelOf( row, requiredKeep ),
									method
								) }
								checked={
									selections[ row.canonicalId ] ===
									requiredKeep
								}
								onChange={ ( isChecked ) =>
									onSelect(
										row.canonicalId,
										isChecked ? requiredKeep : ''
									)
								}
							/>

							{ knockOn.brokenUnits.length > 0 && (
								<Notice
									className="duplicate-resolution-modal__prerequisite"
									status="warning"
									isDismissible={ false }
								>
									<strong>
										{ affectedProviders.length === 1
											? sprintf(
													/* translators: 1: express method names, 2: the provider losing them. */
													__(
														'%1$s will also be disabled for %2$s',
														'woocommerce'
													),
													joinNames( affectedNames ),
													affectedProviders[ 0 ]
											  )
											: sprintf(
													/* translators: %s: express method names. Used when several providers are affected, so they are not all listed. */
													__(
														'%s will also be disabled for other providers',
														'woocommerce'
													),
													joinNames( affectedNames )
											  ) }
									</strong>
									<p>
										{ knockOn.survivingMethods.length > 0
											? sprintf(
													/* translators: %s: the providers still offering these express methods. */
													__(
														'These payment methods will remain available through %s.',
														'woocommerce'
													),
													joinNames(
														knockOn.survivingVia
													)
											  )
											: __(
													'These payment methods will no longer be available at checkout.',
													'woocommerce'
											  ) }
									</p>
								</Notice>
							) }
						</CardBody>
					</WPCard>
				);
			}

			return (
				<div
					key={ row.canonicalId }
					className="duplicate-resolution-modal__row"
				>
					<div className="duplicate-resolution-modal__row-icon">
						{ row.icon }
					</div>
					<div className="duplicate-resolution-modal__row-details">
						<span className="duplicate-resolution-modal__row-title">
							{ row.label }
						</span>
						<SelectControl
							__nextHasNoMarginBottom
							className="duplicate-resolution-modal__row-select"
							aria-label={ __(
								'Choose a provider',
								'woocommerce'
							) }
							value={ selections[ row.canonicalId ] ?? '' }
							options={ [
								{
									label: __(
										'Choose a provider',
										'woocommerce'
									),
									value: '',
								},
								...row.options.map( ( option ) => ( {
									label: option.providerLabel,
									value: option.gatewayId,
								} ) ),
							] }
							onChange={ ( value ) =>
								onSelect( row.canonicalId, value )
							}
						/>
					</div>
				</div>
			);
		} ) }
	</div>
);
