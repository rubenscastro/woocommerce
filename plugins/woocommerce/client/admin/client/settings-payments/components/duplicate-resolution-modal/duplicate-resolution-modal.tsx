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
	// A duplicate whose only valid keep is fixed by the server (an implementation that cannot be
	// disabled) starts already selected; the rest start empty for the merchant to choose.
	const [ selections, setSelections ] = useState< Record< string, string > >(
		() =>
			rows.reduce< Record< string, string > >( ( initial, row ) => {
				if ( row.requiredKeepGatewayId ) {
					initial[ row.canonicalId ] = row.requiredKeepGatewayId;
				}
				return initial;
			}, {} )
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

	// Every duplicate must have an explicit provider chosen before the resolution can be applied. There
	// is no default selection, so an untouched or placeholder row leaves the primary action disabled.
	const allChosen = useMemo(
		() => rows.every( ( row ) => Boolean( selections[ row.canonicalId ] ) ),
		[ rows, selections ]
	);

	const onSelect = ( canonicalId: string, gatewayId: string ) =>
		setSelections( ( current ) => ( {
			...current,
			[ canonicalId ]: gatewayId,
		} ) );

	const isLastStep = step === totalSteps;

	const handleApply = async () => {
		setIsSaving( true );
		setError( null );

		try {
			const result = ( await resolvePaymentMethodDuplicates(
				selections
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

	// Only the regular step gates on having a choice for every duplicate; the express shell collects
	// nothing, so it never blocks the primary action.
	const primaryDisabled = ( step === 1 && ! allChosen ) || isSaving;

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
