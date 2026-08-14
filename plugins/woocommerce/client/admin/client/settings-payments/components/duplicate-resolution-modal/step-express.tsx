/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

interface ExpressItem {
	/**
	 * Human-readable label for the express (wallet) method.
	 */
	label: string;
	/**
	 * The gateway ids that implement it.
	 */
	gatewayIds: string[];
}

interface StepExpressProps {
	/**
	 * The detected express duplicate groups, shown read-only.
	 */
	items: ExpressItem[];
}

/**
 * Step 2 of the resolution modal: express (wallet) checkout methods.
 *
 * Prepared shell only — this step is intentionally non-mutating for now. It surfaces detected express
 * duplicates so the flow matches the two-step design, but it collects nothing and never submits any
 * express selection. Express resolution will be implemented as a later increment.
 */
export const StepExpress = ( { items }: StepExpressProps ) => (
	<div className="duplicate-resolution-modal__express">
		<p className="duplicate-resolution-modal__description">
			{ __(
				'These express checkout methods are offered through more than one provider. Resolving express duplicates is coming soon.',
				'woocommerce'
			) }
		</p>
		{ items.length > 0 && (
			<ul className="duplicate-resolution-modal__express-list">
				{ items.map( ( item ) => (
					<li key={ item.label }>
						<span className="duplicate-resolution-modal__express-name">
							{ item.label }
						</span>
						<span className="duplicate-resolution-modal__express-providers">
							{ item.gatewayIds.join( ', ' ) }
						</span>
					</li>
				) ) }
			</ul>
		) }
	</div>
);
