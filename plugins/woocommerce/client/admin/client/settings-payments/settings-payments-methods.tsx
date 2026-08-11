/**
 * External dependencies
 */
import {
	getPaymentMethods,
	type RegisteredPaymentMethod,
} from '@woocommerce/blocks-registry';
import type { ReactElement, ReactNode } from 'react';
import {
	Component,
	cloneElement,
	isValidElement,
	useState,
} from '@wordpress/element';
import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';

/**
 * Stand-in for the Checkout Block's `PaymentMethodLabel`.
 *
 * A registered `label` is a React element that the Checkout Block renders with a `components`
 * prop, and both core and third-party labels destructure `PaymentMethodLabel` out of it. The real
 * component lives in the blocks client and is not published under a script handle, so this
 * renders just the text — which is the part we want here. Deliberately ignoring the `icon` prop
 * also means the provider's icon component is never invoked.
 */
const PaymentMethodLabel = ( { text = '' }: { text?: string } ) => (
	<>{ text }</>
);

const labelComponents = {
	PaymentMethodLabel,
	PaymentMethodIcons: () => null,
};

/**
 * The gateway id a registered method corresponds to.
 *
 * `paymentMethodId` is what gets posted as `payment_method` at checkout and defaults to `name`,
 * so it is the closest thing the registration carries to a server-side identity.
 */
const gatewayIdOf = ( paymentMethod: RegisteredPaymentMethod ): string =>
	paymentMethod.paymentMethodId || paymentMethod.name;

/**
 * Renders `fallback` if the payment method's label component throws.
 *
 * Labels are third-party code written for the checkout context, so they can fail here for reasons
 * we cannot anticipate. One failing label must not take down the list.
 */
class LabelBoundary extends Component<
	{ children: ReactNode; fallback: ReactNode },
	{ hasError: boolean }
> {
	constructor( props: { children: ReactNode; fallback: ReactNode } ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	render() {
		return this.state.hasError ? this.props.fallback : this.props.children;
	}
}

type GroupedProvider = {
	childGatewayIds: string[];
	showChildren: boolean;
};

type Row = {
	paymentMethod: RegisteredPaymentMethod;
	isProvider: boolean;
	isChild: boolean;
};

/**
 * Flatten the registry into the rows to render, applying any provider grouping.
 *
 * `groupedProviders` comes from the server and is keyed by the parent's gateway id. A grouped
 * provider renders as a single row named after the provider, with its methods nested beneath —
 * or with no children at all when the provider renders them inside its own block, since which
 * ones actually appear is then decided by conditions the provider owns.
 *
 * A group whose parent is not in the registry is ignored, so its methods still show up flat
 * rather than disappearing.
 */
const buildRows = (
	paymentMethods: RegisteredPaymentMethod[],
	groupedProviders: Record< string, GroupedProvider >
): Row[] => {
	const byGatewayId = new Map(
		paymentMethods.map( ( method ) => [ gatewayIdOf( method ), method ] )
	);
	const activeGroups = Object.entries( groupedProviders ).filter(
		( [ parentGatewayId ] ) => byGatewayId.has( parentGatewayId )
	);
	const groupedChildIds = new Set(
		activeGroups.flatMap( ( [ , group ] ) => group.childGatewayIds )
	);

	return paymentMethods.flatMap( ( paymentMethod ) => {
		const gatewayId = gatewayIdOf( paymentMethod );

		// Rendered under its parent instead, or not at all.
		if ( groupedChildIds.has( gatewayId ) ) {
			return [];
		}

		const group = groupedProviders[ gatewayId ];

		if ( ! group ) {
			return [ { paymentMethod, isProvider: false, isChild: false } ];
		}

		const children = group.showChildren
			? group.childGatewayIds
					.map( ( childId ) => byGatewayId.get( childId ) )
					.filter( ( child ): child is RegisteredPaymentMethod =>
						Boolean( child )
					)
					.map( ( child ) => ( {
						paymentMethod: child,
						isProvider: false,
						isChild: true,
					} ) )
			: [];

		return [
			{ paymentMethod, isProvider: true, isChild: false },
			...children,
		];
	} );
};

/**
 * The human-readable name of a registered payment method.
 *
 * `ariaLabel` is the only plain string on the config, but it is an accessibility label rather than
 * a title: WooPayments, for example, sets it to "WooPayments" for every method it registers and
 * carries the actual name ("iDEAL", "Bancontact", …) inside `label`. So render `label` and fall
 * back to `ariaLabel` only when that is not possible.
 *
 * The exception is a provider row, which stands in for the whole provider rather than for one of
 * its methods — Stripe's "Credit / Debit Card" would misdescribe it. There the accessibility label
 * is the right string, because that is precisely where these providers put their own name.
 */
const PaymentMethodName = ( {
	paymentMethod,
	isProvider = false,
}: {
	paymentMethod: RegisteredPaymentMethod;
	isProvider?: boolean;
} ) => {
	const { label, ariaLabel, name } = paymentMethod;
	const fallback = ariaLabel || name;

	if ( isProvider ) {
		return <>{ fallback }</>;
	}

	if ( ! isValidElement( label ) ) {
		return <>{ fallback }</>;
	}

	const element = label as ReactElement;

	// Only components read the `components` prop. Stripe's label is a Fragment of host elements
	// and PayPal's is a bare `<img>`; passing it to either is at best ignored and at worst a
	// React warning, so render those as they are.
	const isComponent = typeof element.type === 'function';

	return (
		<LabelBoundary fallback={ fallback }>
			{ isComponent
				? cloneElement( element, { components: labelComponents } )
				: element }
		</LabelBoundary>
	);
};

/**
 * Lists the individual payment methods registered for the Checkout Block.
 *
 * The list comes from the client-side registry that the Checkout Block itself uses: the active
 * Blocks payment method integrations are enqueued on this settings section (see
 * `BlocksPaymentMethodsSpikeController`), their scripts call `registerPaymentMethod()`, and this
 * component reads the result via `getPaymentMethods()`.
 *
 * Note this is deliberately *not* `availablePaymentMethods`, which is cart-specific and only
 * resolved once `canMakePayment()` has run against a real cart.
 */
export const SettingsPaymentsMethods = () => {
	// The registry is populated synchronously by the payment method scripts, which run before
	// this bundle. Read it once so the list does not change under the user.
	const [ rows ] = useState( () => {
		const { groupedProviders = {} } = getSetting<
			Record< string, Record< string, GroupedProvider > >
		>( 'blocksPaymentMethodsSpike', {} );

		return buildRows(
			Object.values( getPaymentMethods() ),
			groupedProviders
		);
	} );

	return (
		<div className="settings-payments-methods__container">
			<h2>{ __( 'Payment methods', 'woocommerce' ) }</h2>
			{ rows.length === 0 ? (
				<p>
					{ __(
						'No Checkout Block payment methods are registered. Enable at least one payment method that supports the Checkout Block.',
						'woocommerce'
					) }
				</p>
			) : (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Payment method', 'woocommerce' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map(
							( { paymentMethod, isProvider, isChild } ) => (
								<tr key={ paymentMethod.name }>
									<td
										style={ {
											paddingInlineStart: isChild
												? '2.5em'
												: undefined,
										} }
									>
										<PaymentMethodName
											paymentMethod={ paymentMethod }
											isProvider={ isProvider }
										/>
									</td>
								</tr>
							)
						) }
					</tbody>
				</table>
			) }
		</div>
	);
};

export default SettingsPaymentsMethods;
