/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import type {
	DuplicateResolutionReport,
	ExpressDuplicateResolutionReport,
} from '@woocommerce/data';

/**
 * Internal dependencies
 */
import type { DuplicateResolutionRow, ExpressDuplicateGroup } from './types';

interface StepDiagnosticProps {
	/**
	 * The authoritative server resolution report returned by the resolve endpoint.
	 */
	report: DuplicateResolutionReport;
	/**
	 * The rows the modal offered, used only to resolve human-readable method and provider labels.
	 */
	rows: DuplicateResolutionRow[];
	/**
	 * The express report, when the merchant made an express choice.
	 */
	expressReport?: ExpressDuplicateResolutionReport | null;
	/**
	 * The express decisions the modal offered, used to resolve provider labels.
	 */
	expressGroups?: ExpressDuplicateGroup[];
	/**
	 * The provider chosen per express decision.
	 */
	expressSelections?: Record< string, string >;
	/**
	 * Readable labels for every express method.
	 */
	walletLabels?: Record< string, string >;
}

/**
 * TEMPORARY spike diagnostics — not final product UI.
 *
 * Renders exactly what the server did and what happened, straight from the structured resolution
 * reports (never a client-side reconstruction), so the spike can be observed without digging through
 * logs. What was kept reads green and what was turned off reads red, since that is the one thing
 * worth checking at a glance.
 *
 * Express results are reported separately because they are a separate request against a separate
 * endpoint, and because what gets turned off there is a *control unit* — which may take methods with
 * it that the merchant never selected.
 *
 * Remove this, its styles, and the report-view branch in the modal once the spike is done.
 */
export const StepDiagnostic = ( {
	report,
	rows,
	expressReport = null,
	expressGroups = [],
	expressSelections = {},
	walletLabels = {},
}: StepDiagnosticProps ) => {
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

	// A control unit belongs to whichever provider option lists it.
	const providerForUnit = ( controlUnitId: string ) => {
		for ( const group of expressGroups ) {
			const option = group.options.find( ( candidate ) =>
				candidate.controlUnitIds.includes( controlUnitId )
			);

			if ( option ) {
				return option.providerLabel;
			}
		}

		return controlUnitId;
	};

	const hasExpress =
		expressReport !== null &&
		( expressGroups.length > 0 || expressReport.error !== null );

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
							<li className="is-failed">
								{ __( 'Not resolved', 'woocommerce' ) } —{ ' ' }
								{ result.error }
							</li>
						) : (
							<>
								<li className="is-kept">
									{ providerLabel(
										result.canonicalId,
										result.kept
									) }{ ' ' }
									— { __( 'kept', 'woocommerce' ) }
								</li>
								{ result.expressDisabled?.map( ( outcome ) => (
									<li
										key={ outcome.controlUnitId }
										className={
											outcome.status === 'disabled'
												? 'is-disabled'
												: 'is-failed'
										}
									>
										{ sprintf(
											/* translators: %s: the provider losing its express methods. */
											__(
												'%s express checkout',
												'woocommerce'
											),
											outcome.providerLabel
										) }{ ' ' }
										— { outcome.status }
										{ outcome.message
											? `: ${ outcome.message }`
											: '' }
									</li>
								) ) }
								{ result.disabled.map( ( outcome ) => (
									<li
										key={ outcome.gatewayId }
										className={
											outcome.status === 'disabled'
												? 'is-disabled'
												: 'is-failed'
										}
									>
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

			{ hasExpress && expressReport && (
				<div className="duplicate-resolution-modal__diagnostic-group">
					<span className="duplicate-resolution-modal__diagnostic-method">
						{ __( 'Express checkout', 'woocommerce' ) }
					</span>
					<ul className="duplicate-resolution-modal__diagnostic-lines">
						{ expressReport.error ? (
							<li className="is-failed">
								{ __( 'Not resolved', 'woocommerce' ) } —{ ' ' }
								{ expressReport.error }
							</li>
						) : (
							<>
								{ expressGroups.map( ( group ) => {
									const chosen = group.options.find(
										( option ) =>
											option.providerSlug ===
											expressSelections[ group.id ]
									);

									if ( ! chosen ) {
										return null;
									}

									return (
										<li
											key={ group.id }
											className="is-kept"
										>
											{ group.label }:{ ' ' }
											{ chosen.providerLabel } —{ ' ' }
											{ __( 'kept', 'woocommerce' ) }
										</li>
									);
								} ) }
								{ expressReport.disabled.map( ( outcome ) => (
									<li
										key={ outcome.controlUnitId }
										className={
											outcome.status === 'disabled'
												? 'is-disabled'
												: 'is-failed'
										}
									>
										{ providerForUnit(
											outcome.controlUnitId
										) }{ ' ' }
										— { outcome.status }
										{ outcome.message
											? `: ${ outcome.message }`
											: '' }
									</li>
								) ) }
								{ expressReport.lost_wallets.map(
									( walletId ) => (
										<li
											key={ walletId }
											className="is-disabled"
										>
											{ walletLabels[ walletId ] ??
												walletId }{ ' ' }
											—{ ' ' }
											{ __(
												'no longer offered',
												'woocommerce'
											) }
										</li>
									)
								) }
							</>
						) }
					</ul>
				</div>
			) }
		</div>
	);
};
