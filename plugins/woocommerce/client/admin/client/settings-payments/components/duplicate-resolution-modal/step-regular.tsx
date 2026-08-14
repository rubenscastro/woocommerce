/**
 * External dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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
	 * Record a provider choice for a canonical method.
	 */
	onSelect: ( canonicalId: string, gatewayId: string ) => void;
}

/**
 * Step 1 of the resolution modal: choose which provider to keep for each duplicated regular method.
 */
export const StepRegular = ( {
	rows,
	selections,
	onSelect,
}: StepRegularProps ) => (
	<div className="duplicate-resolution-modal__list">
		{ rows.map( ( row ) => (
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
						aria-label={ __( 'Choose a provider', 'woocommerce' ) }
						// When one implementation cannot be disabled the server fixes the keep choice, so
						// the control is preselected and locked — the merchant cannot switch it.
						disabled={ Boolean( row.requiredKeepGatewayId ) }
						value={ selections[ row.canonicalId ] ?? '' }
						options={ [
							{
								label: __( 'Choose a provider', 'woocommerce' ),
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
		) ) }
	</div>
);
