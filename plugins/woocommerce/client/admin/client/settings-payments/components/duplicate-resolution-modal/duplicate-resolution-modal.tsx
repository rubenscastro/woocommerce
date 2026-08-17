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
} from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { StepRegular } from './step-regular';
import { StepExpress } from './step-express';
import { StepDiagnostic } from './step-diagnostic';
import type { DuplicateResolutionRow } from './types';
import './duplicate-resolution-modal.scss';

interface ExpressItem {
	label: string;
	gatewayIds: string[];
}

interface DuplicateResolutionModalProps {
	/**
	 * One row per resolvable regular duplicate (Step 1).
	 */
	rows: DuplicateResolutionRow[];
	/**
	 * The detected express duplicates for the prepared Step 2 shell. When empty, the modal is a single
	 * step.
	 */
	expressItems: ExpressItem[];
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
	expressItems,
	onClose,
}: DuplicateResolutionModalProps ) => {
	const hasExpressStep = expressItems.length > 0;
	const totalSteps = hasExpressStep ? 2 : 1;

	const [ step, setStep ] = useState( 1 );
	// Every row starts unchosen: free-choice rows wait for a provider selection, and a required-keep row
	// (rendered as an opt-in checkbox) waits for the merchant to tick "use this provider and hide the
	// duplicates". Nothing is preselected, so an untouched required-keep method is simply left as is.
	const [ selections, setSelections ] = useState< Record< string, string > >(
		{}
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	// TEMPORARY spike diagnostics: once Apply returns, hold the authoritative server report so the
	// modal can show exactly what was attempted and what happened (see StepDiagnostic). Remove with the
	// diagnostic view when the spike is done.
	const [ report, setReport ] = useState< DuplicateResolutionReport | null >(
		null
	);

	const { resolvePaymentMethodDuplicates } =
		useDispatch( paymentSettingsStore );

	// The merchant can apply once every free-choice duplicate has a provider chosen and at least one
	// method is set to resolve. Required-keep rows are opt-in checkboxes, so leaving them unticked never
	// blocks the action — it just leaves that method untouched.
	const canApply = useMemo( () => {
		const freeChoiceChosen = rows.every( ( row ) =>
			row.requiredKeepGatewayId
				? true
				: Boolean( selections[ row.canonicalId ] )
		);
		const anyChosen = Object.values( selections ).some( Boolean );

		return freeChoiceChosen && anyChosen;
	}, [ rows, selections ] );

	const onSelect = ( canonicalId: string, gatewayId: string ) =>
		setSelections( ( current ) => ( {
			...current,
			[ canonicalId ]: gatewayId,
		} ) );

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

		try {
			const result = ( await resolvePaymentMethodDuplicates(
				chosen
			) ) as DuplicateResolutionReport | undefined;

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

	// Only the regular step gates on having something to resolve; the express shell collects nothing, so
	// it never blocks the primary action.
	const primaryDisabled = ( step === 1 && ! canApply ) || isSaving;

	return (
		<Modal
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
					<StepDiagnostic report={ report } rows={ rows } />
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
					{ step === 1 ? (
						<>
							<p className="duplicate-resolution-modal__description">
								{ __(
									'Choose which provider to use for each payment method. The method will be disabled for the other providers to prevent duplicate options at checkout.',
									'woocommerce'
								) }
							</p>
							<StepRegular
								rows={ rows }
								selections={ selections }
								onSelect={ onSelect }
							/>
						</>
					) : (
						<StepExpress items={ expressItems } />
					) }

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
