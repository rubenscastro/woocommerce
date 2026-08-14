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

	// The human-readable method name for an error line. The canonical id is the only stable text
	// identifier we have here (the row's visible name is a rendered element), so present it readably.
	const methodLabel = ( canonicalId: string ) =>
		canonicalId
			.replace( /_/g, ' ' )
			.replace( /\b\w/g, ( character ) => character.toUpperCase() );

	// The specific methods (and providers, when the failure is provider-specific) that did not resolve,
	// so the merchant sees exactly what needs attention rather than a generic message.
	const describeFailures = (
		report: DuplicateResolutionReport
	): string[] => {
		const items: string[] = [];

		report.results.forEach( ( result ) => {
			if ( result.error ) {
				items.push( methodLabel( result.canonicalId ) );
				return;
			}

			const row = rows.find(
				( candidate ) => candidate.canonicalId === result.canonicalId
			);

			result.disabled
				.filter( ( outcome ) => outcome.status !== 'disabled' )
				.forEach( ( outcome ) => {
					const providerLabel =
						row?.options.find(
							( option ) => option.gatewayId === outcome.gatewayId
						)?.providerLabel ?? outcome.gatewayId;

					items.push(
						`${ methodLabel(
							result.canonicalId
						) } (${ providerLabel })`
					);
				} );
		} );

		return items;
	};

	const handleApply = async () => {
		setIsSaving( true );
		setError( null );

		try {
			const report = ( await resolvePaymentMethodDuplicates(
				selections
			) ) as DuplicateResolutionReport | undefined;

			if ( report?.success ) {
				// The disabled methods drop out of the list and the badges recompute on a fresh load;
				// the server response is already the authoritative post-resolution state.
				window.location.reload();
				return;
			}

			const failures = report ? describeFailures( report ) : [];

			setError(
				failures.length
					? sprintf(
							/* translators: %s: comma-separated list of payment methods that could not be resolved. */
							__(
								'These payment methods couldn’t be resolved: %s. Your other methods are unchanged.',
								'woocommerce'
							),
							failures.join( ', ' )
					  )
					: __(
							'Some payment methods could not be resolved. Your other methods are unchanged. Please review and try again.',
							'woocommerce'
					  )
			);
			setIsSaving( false );
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
		</Modal>
	);
};

export default DuplicateResolutionModal;
