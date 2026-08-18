/**
 * External dependencies
 */
import { Button, Modal, Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import {
	paymentSettingsStore,
	type DuplicateResolutionReport,
	type ExpressDuplicateResolutionReport,
} from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { StepRegular } from './step-regular';
import { StepExpress } from './step-express';
import { StepDiagnostic } from './step-diagnostic';
import {
	calculateExpressImpact,
	expandGroupSelections,
} from './express-impact';
import type {
	DuplicateResolutionRow,
	ExpressControlUnit,
	ExpressDuplicateGroup,
} from './types';
import './duplicate-resolution-modal.scss';

interface DuplicateResolutionModalProps {
	/**
	 * One row per resolvable regular duplicate (Step 1).
	 */
	rows: DuplicateResolutionRow[];
	/**
	 * One entry per express decision (Step 2). When empty, the modal is a single step.
	 */
	expressGroups: ExpressDuplicateGroup[];
	/**
	 * The full control-unit graph, used to preview what a wallet choice would turn off.
	 */
	expressControlUnits: ExpressControlUnit[];
	/**
	 * Readable labels for every wallet in the graph, including ones with no row of their own.
	 */
	expressWalletLabels: Record< string, string >;
	/**
	 * Called when the modal should close (✕, Cancel, or after a successful resolution reload).
	 */
	onClose: () => void;
}

/**
 * The "Resolve duplicate payment methods" modal.
 *
 * Step 1 (regular) is functional: the merchant chooses one provider to keep per duplicated method and
 * the server disables the others. Step 2 (express) is a prepared, non-mutating shell shown only when
 * express duplicates exist. The submitted payload only ever contains the regular selections.
 */
export const DuplicateResolutionModal = ( {
	rows,
	expressGroups,
	expressControlUnits,
	expressWalletLabels,
	onClose,
}: DuplicateResolutionModalProps ) => {
	// A required-keep method (WooPayments Card) is not "which provider?" but "do you want this
	// provider to own the method and hide the rest?" — a different question with a different answer
	// shape, so it gets its own step, first. The remaining duplicates are a straight provider choice,
	// and express is a third kind again. Only the steps that have something to ask are shown.
	const requiredRows = useMemo(
		() => rows.filter( ( row ) => Boolean( row.requiredKeepGatewayId ) ),
		[ rows ]
	);
	const choiceRows = useMemo(
		() => rows.filter( ( row ) => ! row.requiredKeepGatewayId ),
		[ rows ]
	);

	const steps = useMemo( () => {
		const ordered: Array< 'required' | 'regular' | 'express' > = [];

		if ( requiredRows.length > 0 ) {
			ordered.push( 'required' );
		}

		if ( choiceRows.length > 0 ) {
			ordered.push( 'regular' );
		}

		if ( expressGroups.length > 0 ) {
			ordered.push( 'express' );
		}

		return ordered;
	}, [ requiredRows, choiceRows, expressGroups ] );

	const totalSteps = steps.length;

	const [ step, setStep ] = useState( 1 );
	// Every row starts unchosen: free-choice rows wait for a provider selection, and a required-keep row
	// (rendered as an opt-in checkbox) waits for the merchant to tick "use this provider and hide the
	// duplicates". Nothing is preselected, so an untouched required-keep method is simply left as is.
	const [ selections, setSelections ] = useState< Record< string, string > >(
		{}
	);
	// Express selections are held separately from the regular ones: they are chosen per wallet and
	// resolved per control unit, and they are not part of the regular payload.
	const [ expressSelections, setExpressSelections ] = useState<
		Record< string, string >
	>( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	// TEMPORARY spike diagnostics: once Apply returns, hold the authoritative server report so the
	// modal can show exactly what was attempted and what happened (see StepDiagnostic). Remove with the
	// diagnostic view when the spike is done.
	const [ report, setReport ] = useState< DuplicateResolutionReport | null >(
		null
	);
	const [ expressReport, setExpressReport ] =
		useState< ExpressDuplicateResolutionReport | null >( null );

	const { resolvePaymentMethodDuplicates, resolveExpressMethodDuplicates } =
		useDispatch( paymentSettingsStore );

	// Every free-choice duplicate needs a provider before that step can be left. Required-keep rows
	// are opt-in toggles, so leaving one untouched never blocks — it just leaves that method as it is.
	const choiceRowsAnswered = useMemo(
		() =>
			choiceRows.every( ( row ) =>
				Boolean( selections[ row.canonicalId ] )
			),
		[ choiceRows, selections ]
	);

	// There has to be something to actually do before applying.
	const anyChosen = useMemo(
		() =>
			Object.values( selections ).some( Boolean ) ||
			Object.values( expressSelections ).some( Boolean ),
		[ selections, expressSelections ]
	);

	const onSelect = ( canonicalId: string, gatewayId: string ) =>
		setSelections( ( current ) => ( {
			...current,
			[ canonicalId ]: gatewayId,
		} ) );

	const onExpressSelect = ( groupId: string, providerSlug: string ) =>
		setExpressSelections( ( current ) => ( {
			...current,
			[ groupId ]: providerSlug,
		} ) );

	// A consequence is an accepted outcome and never blocks. A conflict (or a unit that reports it
	// cannot be turned off) has no coherent result, so it does.
	// Conflicts cannot arise: there is one choice per decision group, and groups are disjoint. Only
	// a provider that reports it cannot be turned off can still block.
	const expressImpact = useMemo(
		() =>
			calculateExpressImpact(
				expressControlUnits,
				expandGroupSelections( expressGroups, expressSelections )
			),
		[ expressControlUnits, expressGroups, expressSelections ]
	);
	const expressBlocked = expressImpact.blocked.length > 0;

	const currentStep = steps[ step - 1 ];
	const isLastStep = step === totalSteps;

	const handleApply = async () => {
		setIsSaving( true );
		setError( null );

		// Only submit methods the merchant actually set to resolve; unticked/placeholder rows are omitted.
		const chosen = Object.fromEntries(
			Object.entries( selections ).filter( ( [ , gatewayId ] ) =>
				Boolean( gatewayId )
			)
		);
		const expressChosen = Object.fromEntries(
			Object.entries( expressSelections ).filter( ( [ , unitId ] ) =>
				Boolean( unitId )
			)
		);

		try {
			const result = ( await resolvePaymentMethodDuplicates(
				chosen
			) ) as DuplicateResolutionReport | undefined;

			// Express resolution is a separate request against a separate endpoint, so the regular
			// flow behaves exactly as before whether or not there are express choices to apply. The
			// methods the merchant was shown as being disabled travel with it: the server recomputes
			// the impact and refuses if it no longer matches.
			if ( Object.keys( expressChosen ).length > 0 ) {
				// A refusal is reported in the diagnostic alongside the regular results rather than
				// replacing the whole view with a generic error: the merchant still needs to see
				// what did happen, and the reason the express half did not.
				setExpressReport(
					( ( await resolveExpressMethodDuplicates(
						expandGroupSelections( expressGroups, expressChosen ),
						expressImpact.lostWallets
					) ) as ExpressDuplicateResolutionReport | undefined ) ??
						null
				);
			}

			setIsSaving( false );

			if ( ! result ) {
				setError(
					__(
						'The duplicates could not be resolved. Please try again.',
						'woocommerce'
					)
				);
				return;
			}

			// TEMPORARY spike diagnostics: show exactly what the server did — for full, partial, and
			// failed resolutions alike — instead of reloading straight away on success. The report is
			// the authoritative post-resolution state; the list refreshes when the merchant closes it.
			setReport( result );
		} catch {
			setError(
				__(
					'The duplicates could not be resolved. Please try again.',
					'woocommerce'
				)
			);
			setIsSaving( false );
		}
	};

	// Closing the diagnostic reloads so the list reflects the server's post-resolution state.
	const handleDiagnosticDone = () => window.location.reload();

	const handlePrimary = () => {
		if ( isLastStep ) {
			void handleApply();
			return;
		}

		setStep( ( current ) => current + 1 );
	};

	// Each step gates on its own question: the provider-choice step needs every duplicate answered,
	// and the express step needs no provider that refuses to be turned off. The final step also needs
	// something to actually apply — a merchant who left the opt-in untouched has asked for nothing.
	const primaryDisabled =
		( currentStep === 'regular' && ! choiceRowsAnswered ) ||
		( currentStep === 'express' && expressBlocked ) ||
		( isLastStep && ! anyChosen ) ||
		isSaving;

	return (
		<Modal
			size="medium"
			className="duplicate-resolution-modal"
			title={ __( 'Resolve duplicate payment methods', 'woocommerce' ) }
			onRequestClose={ onClose }
			shouldCloseOnClickOutside={ false }
		>
			{ error && (
				<Notice
					className="duplicate-resolution-modal__error"
					status="error"
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			{ report ? (
				<>
					<div className="duplicate-resolution-modal__step-body">
						<StepDiagnostic
							report={ report }
							rows={ rows }
							expressReport={ expressReport }
							expressGroups={ expressGroups }
							expressSelections={ expressSelections }
							walletLabels={ expressWalletLabels }
						/>
					</div>
					<div className="duplicate-resolution-modal__footer">
						<span className="duplicate-resolution-modal__step-indicator" />
						<div className="duplicate-resolution-modal__actions">
							<Button
								variant="primary"
								onClick={ handleDiagnosticDone }
							>
								{ __( 'Done', 'woocommerce' ) }
							</Button>
						</div>
					</div>
				</>
			) : (
				<>
					<div className="duplicate-resolution-modal__step-body">
						{ currentStep === 'required' && (
							<StepRegular
								rows={ requiredRows }
								selections={ selections }
								onSelect={ onSelect }
								controlUnits={ expressControlUnits }
								walletLabels={ expressWalletLabels }
							/>
						) }
						{ currentStep === 'regular' && (
							<>
								<p className="duplicate-resolution-modal__description">
									{ __(
										'Choose which provider to use for each payment method. The method will be disabled for the other providers to prevent duplicate options at checkout.',
										'woocommerce'
									) }
								</p>
								<StepRegular
									rows={ choiceRows }
									selections={ selections }
									onSelect={ onSelect }
								/>
							</>
						) }
						{ currentStep === 'express' && (
							<StepExpress
								groups={ expressGroups }
								controlUnits={ expressControlUnits }
								walletLabels={ expressWalletLabels }
								selections={ expressSelections }
								onSelect={ onExpressSelect }
							/>
						) }
					</div>

					<div className="duplicate-resolution-modal__footer">
						<span className="duplicate-resolution-modal__step-indicator">
							{ totalSteps > 1 &&
								sprintf(
									/* translators: 1: current step number, 2: total number of steps. */
									__( 'Step %1$d of %2$d', 'woocommerce' ),
									step,
									totalSteps
								) }
						</span>
						<div className="duplicate-resolution-modal__actions">
							{ step > 1 && (
								<Button
									variant="tertiary"
									onClick={ () =>
										setStep( ( current ) => current - 1 )
									}
									disabled={ isSaving }
								>
									{ __( 'Back', 'woocommerce' ) }
								</Button>
							) }
							<Button
								variant="primary"
								onClick={ handlePrimary }
								isBusy={ isSaving }
								disabled={ primaryDisabled }
							>
								{ isLastStep
									? __( 'Apply', 'woocommerce' )
									: __( 'Continue', 'woocommerce' ) }
							</Button>
						</div>
					</div>
				</>
			) }
		</Modal>
	);
};

export default DuplicateResolutionModal;
