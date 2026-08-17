/**
 * External dependencies
 */
import { CheckboxControl, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DuplicateResolutionRow } from './types';

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
}

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
}: StepRegularProps ) => (
	<div className="duplicate-resolution-modal__list">
		{ rows.map( ( row ) => {
			const requiredKeep = row.requiredKeepGatewayId;
			const method = methodNameOf( row );

			return (
				<div
					key={ row.canonicalId }
					className="duplicate-resolution-modal__row"
				>
					<div className="duplicate-resolution-modal__row-icon">
						{ row.icon }
					</div>
					<div className="duplicate-resolution-modal__row-details">
						{ requiredKeep ? (
							<CheckboxControl
								__nextHasNoMarginBottom
								className="duplicate-resolution-modal__row-checkbox"
								label={ sprintf(
									/* translators: 1: provider name, 2: payment method name. */
									__(
										'Use %1$s as the default %2$s provider',
										'woocommerce'
									),
									providerLabelOf( row, requiredKeep ),
									method
								) }
								help={ sprintf(
									/* translators: 1: payment method name, 2: number of providers, 3: provider name, 4: payment method name (lower case). */
									__(
										'%1$s payments are offered by %2$d providers. Use %3$s as the primary option and hide duplicate %4$s payment methods from other providers.',
										'woocommerce'
									),
									method,
									row.options.length,
									providerLabelOf( row, requiredKeep ),
									method.toLowerCase()
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
						) : (
							<>
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
									value={
										selections[ row.canonicalId ] ?? ''
									}
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
							</>
						) }
					</div>
				</div>
			);
		} ) }
	</div>
);
