/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import type { DuplicateResolutionReport } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import type { DuplicateResolutionRow } from './types';

interface StepDiagnosticProps {
	/**
	 * The authoritative server resolution report returned by the resolve endpoint.
	 */
	report: DuplicateResolutionReport;
	/**
	 * The rows the modal offered, used only to resolve human-readable method and provider labels.
	 */
	rows: DuplicateResolutionRow[];
}

/**
 * TEMPORARY spike diagnostics — not final product UI.
 *
 * Renders exactly what the server resolver attempted and what happened, straight from the structured
 * resolution report (never a client-side reconstruction), so the spike can be observed without digging
 * through logs. Shows, per canonical method: the kept implementation, and each disable target with its
 * real result (`disabled`, `failed`, `unsupported`, `skipped`) and any failure message.
 *
 * Remove this, its styles, and the report-view branch in the modal once the spike is done.
 */
export const StepDiagnostic = ( { report, rows }: StepDiagnosticProps ) => {
	const rowFor = ( canonicalId: string ) =>
		rows.find( ( row ) => row.canonicalId === canonicalId );

	// The method's readable name. Row labels are React nodes, but for these are the server-provided
	// method label string; fall back to a humanised canonical id when a row is missing.
	const methodLabel = ( canonicalId: string ) => {
		const label = rowFor( canonicalId )?.label;

		if ( typeof label === 'string' && label ) {
			return label;
		}

		return canonicalId
			.replace( /_/g, ' ' )
			.replace( /\b\w/g, ( character ) => character.toUpperCase() );
	};

	const providerLabel = ( canonicalId: string, gatewayId: string ) =>
		rowFor( canonicalId )?.options.find(
			( option ) => option.gatewayId === gatewayId
		)?.providerLabel ?? gatewayId;

	return (
		<div className="duplicate-resolution-modal__diagnostic">
			<p className="duplicate-resolution-modal__diagnostic-note">
				{ __(
					'Diagnostic result (temporary): exactly what the server attempted and what happened.',
					'woocommerce'
				) }
			</p>
			{ report.results.map( ( result ) => (
				<div
					key={ result.canonicalId }
					className="duplicate-resolution-modal__diagnostic-group"
				>
					<span className="duplicate-resolution-modal__diagnostic-method">
						{ methodLabel( result.canonicalId ) }
					</span>
					<ul className="duplicate-resolution-modal__diagnostic-lines">
						{ result.error ? (
							<li>
								{ __( 'Not resolved', 'woocommerce' ) } —{ ' ' }
								{ result.error }
							</li>
						) : (
							<>
								<li>
									{ providerLabel(
										result.canonicalId,
										result.kept
									) }{ ' ' }
									— { __( 'kept', 'woocommerce' ) }
								</li>
								{ result.disabled.map( ( outcome ) => (
									<li key={ outcome.gatewayId }>
										{ providerLabel(
											result.canonicalId,
											outcome.gatewayId
										) }{ ' ' }
										— { outcome.status }
										{ outcome.message
											? `: ${ outcome.message }`
											: '' }
									</li>
								) ) }
							</>
						) }
					</ul>
				</div>
			) ) }
		</div>
	);
};
