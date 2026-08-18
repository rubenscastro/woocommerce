/**
 * External dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';

/**
 * Internal dependencies
 */
import { DuplicateResolutionModal } from './duplicate-resolution-modal';
import type {
	DuplicateGroups,
	DuplicateProviders,
	DuplicateResolutionRow,
	ExpressControlUnit,
	ExpressDuplicates,
} from './types';
import './duplicate-resolution-entry.scss';

/**
 * The slice of the `blocksPaymentMethodsSpike` payload the duplicate flow reads.
 *
 * Both the Payment methods page and the Payment providers page publish this same server-produced data
 * (see `BlocksPaymentMethodsSpikeController`), so the duplicate notice and modal read one source of
 * truth on either page.
 */
type DuplicateSpikeSettings = {
	duplicates?: DuplicateGroups;
	duplicateProviders?: DuplicateProviders;
	expressDuplicates?: ExpressDuplicates;
	expressControlUnits?: ExpressControlUnit[];
	expressWalletLabels?: Record< string, string >;
};

/**
 * Build the modal's rows from the server-produced `duplicateProviders` payload.
 *
 * The method icon and label come from the server (they describe the payment method's own identity, so
 * Card shows the generic Card icon rather than a provider logo), which keeps this free of the
 * client-side payment-method registry and identical on both pages that host the modal.
 */
export const buildDuplicateResolutionRows = (
	duplicateProviders: DuplicateProviders
): DuplicateResolutionRow[] =>
	Object.entries( duplicateProviders )
		.map( ( [ canonicalId, candidate ] ) => ( {
			canonicalId,
			options: candidate.implementations,
			requiredKeepGatewayId: candidate.requiredKeepGatewayId,
			icon: candidate.methodIcon ? (
				<img src={ candidate.methodIcon } alt="" />
			) : (
				<div
					className="duplicate-resolution-modal__row-placeholder"
					aria-hidden="true"
				/>
			),
			label: candidate.methodLabel || canonicalId,
		} ) )
		// Required-keep methods (e.g. Card) render as the primary opt-in toggle, so surface them first.
		.sort(
			( a, b ) =>
				( b.requiredKeepGatewayId ? 1 : 0 ) -
				( a.requiredKeepGatewayId ? 1 : 0 )
		);

interface DuplicateResolutionEntryProps {
	/**
	 * Optional class for the notice wrapper, so each page can inset the banner to match its list.
	 */
	wrapperClassName?: string;
}

/**
 * The shared duplicate warning notice and its resolution modal.
 *
 * Reads the server-detected duplicates once (the single source of truth for detection and the
 * resolvable candidate model) and renders the same warning + modal on any page that hosts it. Renders
 * nothing when there are no resolvable regular duplicates.
 */
export const DuplicateResolutionEntry = ( {
	wrapperClassName,
}: DuplicateResolutionEntryProps ) => {
	const {
		duplicateProviders = {},
		expressDuplicates = [],
		expressControlUnits = [],
		expressWalletLabels = {},
	} = getSetting< DuplicateSpikeSettings >( 'blocksPaymentMethodsSpike', {} );

	const rows = useMemo(
		() => buildDuplicateResolutionRows( duplicateProviders ),
		[ duplicateProviders ]
	);

	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ isDismissed, setIsDismissed ] = useState( false );

	if ( rows.length === 0 ) {
		return null;
	}

	return (
		<>
			{ ! isDismissed && (
				<div className={ wrapperClassName }>
					<Notice
						className="settings-payments-methods__duplicate-notice"
						status="warning"
						onRemove={ () => setIsDismissed( true ) }
					>
						<p>
							{ __(
								'Some payment methods are enabled through multiple providers. Review them to avoid showing duplicate options at checkout.',
								'woocommerce'
							) }
						</p>
						<div className="settings-payments-methods__duplicate-notice-actions">
							<Button
								variant="secondary"
								onClick={ () => setIsModalOpen( true ) }
							>
								{ __( 'Review duplicates', 'woocommerce' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setIsDismissed( true ) }
							>
								{ __( 'Cancel', 'woocommerce' ) }
							</Button>
						</div>
					</Notice>
				</div>
			) }
			{ isModalOpen && (
				<DuplicateResolutionModal
					rows={ rows }
					expressGroups={ expressDuplicates }
					expressControlUnits={ expressControlUnits }
					expressWalletLabels={ expressWalletLabels }
					onClose={ () => setIsModalOpen( false ) }
				/>
			) }
		</>
	);
};

export default DuplicateResolutionEntry;
