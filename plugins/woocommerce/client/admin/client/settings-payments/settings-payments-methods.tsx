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
	useMemo,
	useState,
} from '@wordpress/element';
import { Button, ExternalLink, Notice } from '@wordpress/components';
import { Icon, dragHandle } from '@wordpress/icons';
import { useDispatch } from '@wordpress/data';
import { paymentSettingsStore } from '@woocommerce/data';
import clsx from 'clsx';
import { getAdminLink, getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	DefaultDragHandle,
	SortableContainer,
	SortableItem,
} from '~/settings-payments/components/sortable';
import { StatusBadge } from '~/settings-payments/components/status-badge';
import { BackButton } from '~/settings-payments/components/buttons';
// Paints #wpbody and #mainform white for the whole Payments tab. Every other page in this module
// imports it; without it the wp-admin body grey shows through behind the list — most visibly
// behind a row while it is being dragged.
import './settings-payments-body.scss';

type GroupedProvider = {
	childGatewayIds: string[];
	showChildren: boolean;
	optimizedCheckout: boolean;
	settingsUrl: string;
};

type SpikeSettings = {
	groupedProviders?: Record< string, GroupedProvider >;
	gatewayPlugins?: Record< string, string >;
	providerIcons?: Record< string, string >;
	methodIcons?: Record< string, string >;
	providerAssets?: Record< string, string >;
};

export type Row = {
	id: string;
	paymentMethod: RegisteredPaymentMethod;
	group: GroupedProvider | null;
	children: RegisteredPaymentMethod[];
};

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

	if ( isProvider || ! isValidElement( label ) ) {
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
 * The logo of a registered payment method, falling back to its provider's.
 *
 * `icons` is the only icon channel the registration API offers. It is documented as the card
 * brands a method supports rather than as the method's own logo, but for a single-brand method the
 * first entry is effectively that, and it is the one source that needs nothing provider-specific.
 * Entries can also be bare strings naming an icon the Checkout Block resolves internally; those
 * are not resolvable here, so only descriptors with a `src` are used.
 *
 * Almost nothing populates it today, so in practice most rows fall through to the provider logo,
 * and anything with neither keeps the placeholder.
 */
const registeredIconSrc = (
	paymentMethod: RegisteredPaymentMethod
): string | undefined => {
	const icons = paymentMethod.icons;

	if ( ! Array.isArray( icons ) ) {
		return undefined;
	}

	const descriptor = icons.find(
		( icon ) => typeof icon === 'object' && icon !== null && !! icon.src
	);

	return typeof descriptor === 'object' && descriptor !== null
		? descriptor.src ?? undefined
		: undefined;
};

/**
 * A logo WooCommerce already ships, matched to a method id.
 *
 * Gateway ids are conventionally the provider's own prefix followed by the method — `stripe_ideal`,
 * `ppcp-bancontact`, `woocommerce_payments_alipay` — so leading segments are dropped one at a time
 * until a bundled icon name matches. That is a convention rather than a contract, but the cost of
 * a miss is only the next fallback, and the short-candidate guard keeps a stray tail segment from
 * matching something unrelated.
 */
const bundledIconSrc = (
	gatewayId: string,
	icons: Record< string, string >,
	{
		excludeLeading = false,
		preferLeading = false,
	}: { excludeLeading?: boolean; preferLeading?: boolean } = {}
): string | undefined => {
	// Only the method column needs this. There, `stripe_ideal` reaching `stripe` would collapse
	// every sub-method onto its provider's logo. The provider column wants the opposite: matching
	// `airwallex-online-payments-gateway` to `airwallex` is the whole point.
	const segments = gatewayId.split( /[-_]/ );
	const leading = excludeLeading && segments.length > 1 ? segments[ 0 ] : '';

	// Ids read provider-first, so dropping leading segments walks from the whole id towards the
	// method: `woocommerce-gateway-stripe`, `gateway-stripe`, `stripe`.
	const heads: string[] = [];
	let head = gatewayId;

	while ( head.length >= 3 ) {
		heads.push( head );

		const next = head.replace( /^[^-_]+[-_]/, '' );

		if ( next === head ) {
			break;
		}

		head = next;
	}

	// Separators are dropped as well, because an id segments a name the file name runs together:
	// `us_bank_account` has to reach `usbankaccount`.
	const lookup = ( candidate: string ) =>
		[ candidate, candidate.replace( /[-_]/g, '' ) ].find(
			( key ) => key !== leading && icons[ key ]
		);

	// A plugin slug usually opens with the vendor — `airwallex-online-payments-gateway`,
	// `helcim-commerce-for-woocommerce` — so that segment names the provider. Our own namespace is
	// the exception: it prefixes first-party plugins and says nothing about which one.
	if (
		preferLeading &&
		segments.length > 1 &&
		segments[ 0 ] !== 'woocommerce'
	) {
		const vendor = lookup( segments[ 0 ] );

		if ( vendor ) {
			return icons[ vendor ];
		}
	}

	// Whole heads first. Trailing segments are only dropped once nothing matched, so
	// `woocommerce-gateway-stripe` finds `stripe` rather than stopping at `woocommerce`.
	for ( const candidate of heads ) {
		const match = lookup( candidate );

		if ( match ) {
			return icons[ match ];
		}
	}

	// Shortest head first here: it sits closest to the method, so `woocommerce-paypal-payments`
	// reaches `paypal` instead of collapsing onto the `woocommerce` its own prefix would match.
	for ( const candidate of [ ...heads ].reverse() ) {
		let shorter = candidate;

		while ( shorter.length >= 3 ) {
			const next = shorter.replace( /[-_][^-_]+$/, '' );

			if ( next === shorter ) {
				break;
			}

			shorter = next;

			const match = lookup( shorter );

			if ( match ) {
				return icons[ match ];
			}
		}
	}

	return undefined;
};

const MethodIcon = ( {
	paymentMethod,
	icons,
	pluginSlug,
	shape = 'square',
}: {
	paymentMethod: RegisteredPaymentMethod;
	icons: Record< string, string >;
	pluginSlug?: string;
	shape?: 'square' | 'rectangle';
} ) => {
	// Every step stays inside one set. The two are different artwork at different sizes, so mixing
	// them within a column would land some logos at the wrong shape.
	const src =
		registeredIconSrc( paymentMethod ) ??
		bundledIconSrc( gatewayIdOf( paymentMethod ), icons, {
			excludeLeading: true,
		} ) ??
		bundledIconSrc( pluginSlug ?? '', icons, {
			excludeLeading: true,
		} ) ??
		icons.generic;
	const shapeClass =
		shape === 'rectangle'
			? ' settings-payments-methods__icon--rectangle'
			: '';

	if ( ! src ) {
		return (
			<div
				className={ `woocommerce-list__item-image settings-payments-methods__icon-placeholder${ shapeClass }` }
				aria-hidden="true"
			/>
		);
	}

	// The name is right beside it, so the logo adds nothing for a screen reader.
	return (
		<img
			className={ `woocommerce-list__item-image settings-payments-methods__icon${ shapeClass }` }
			src={ src }
			alt=""
		/>
	);
};

/**
 * Occupies the drag handle's footprint on rows that have no handle.
 *
 * Rendered from the same markup as `DefaultDragHandle` so the indent is whatever the handle
 * actually measures, rather than a value copied off a mockup.
 */
const DragHandleSpacer = () => (
	<div
		className="drag-handle-wrapper settings-payments-methods__drag-handle-spacer"
		aria-hidden="true"
	>
		<div className="drag-handle">
			<Icon icon={ dragHandle } size={ 20 } />
		</div>
	</div>
);

/**
 * The logo of the plugin a payment method belongs to, shown on the trailing edge of the row.
 */
const ProviderIcon = ( { src }: { src?: string } ) =>
	src ? (
		<img
			className="settings-payments-methods__provider-icon"
			src={ src }
			alt=""
		/>
	) : null;

/**
 * Group the registry into the rows to render.
 *
 * See `StripeOptimizedCheckoutAdapter` for where `groupedProviders` comes from. A grouped provider
 * renders as one row named after the provider; its methods are nested beneath it, or omitted
 * entirely when the provider renders them inside its own block.
 */
const buildRows = (
	registered: RegisteredPaymentMethod[],
	groupedProviders: Record< string, GroupedProvider >,
	enabledGatewayIds: string[]
): Row[] => {
	// The registry holds everything that registered, not everything checkout offers. Checkout drops
	// a method whose id is not among the cart's payment methods — the enabled gateway ids — which is
	// why Airwallex's POS and Express Checkout never appear there despite registering here.
	// `paymentMethodSortOrder` is that same list and needs no cart to read, so applying it makes the
	// page agree with checkout. Skipped when the list is unavailable, so a missing setting errs
	// towards showing too much rather than nothing.
	const enabled = new Set( enabledGatewayIds );
	const filtered =
		enabled.size > 0
			? registered.filter( ( method ) =>
					enabled.has( gatewayIdOf( method ) )
			  )
			: registered;

	// `enabledGatewayIds` is also the order checkout uses, so sort the methods by it — a stable sort
	// keeps registry order both for ties and for anything not listed (e.g. a newly registered method),
	// which then falls to the end.
	const orderIndex = new Map(
		enabledGatewayIds.map( ( id, index ) => [ id, index ] )
	);
	const positionOf = ( method: RegisteredPaymentMethod ) =>
		orderIndex.get( gatewayIdOf( method ) ) ?? Number.MAX_SAFE_INTEGER;
	const paymentMethods = [ ...filtered ].sort(
		( a, b ) => positionOf( a ) - positionOf( b )
	);

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

		const group = groupedProviders[ gatewayId ] ?? null;
		const children =
			group && group.showChildren
				? group.childGatewayIds
						.map( ( childId ) => byGatewayId.get( childId ) )
						.filter( ( child ): child is RegisteredPaymentMethod =>
							Boolean( child )
						)
				: [];

		return [ { id: gatewayId, paymentMethod, group, children } ];
	} );
};

/**
 * Flatten the rendered rows into the canonical, ordered list of checkout payment-method IDs.
 *
 * The persisted identity of a method is its registry `name` — never a row/group id, `gatewayIdOf`,
 * or `paymentMethodId` — so grouping stays presentation-only and never leaks into what is saved.
 *
 * - a standalone row emits its method's `name`;
 * - a grouped row with Optimized Checkout on has no children, so it emits only the real method
 *   `name` the row stands for (e.g. `stripe`), not a synthetic provider/group id;
 * - a grouped row with Optimized Checkout off emits its method's `name` followed by each nested
 *   child method's `name`, in the order the checkout renders them.
 */
export const serializePaymentMethodOrder = ( rows: Row[] ): string[] =>
	rows.flatMap( ( row ) => [
		row.paymentMethod.name,
		...row.children.map( ( child ) => child.name ),
	] );

/**
 * Whether two ordered id lists are identical.
 */
const ordersEqual = ( a: string[], b: string[] ): boolean =>
	a.length === b.length &&
	a.every( ( value, index ) => value === b[ index ] );

/**
 * Lists the individual payment methods registered for the Checkout Block, in checkout order.
 *
 * The list comes from the client-side registry that the Checkout Block itself uses: the active
 * Blocks payment method integrations are enqueued on this settings section (see
 * `BlocksPaymentMethodsSpikeController`), their scripts call `registerPaymentMethod()`, and this
 * component reads the result via `getPaymentMethods()`.
 *
 * The order is persisted through the settings-payments REST endpoint: Save writes the canonical
 * order (registry `name`s) that both Checkout Block and Classic checkout consume.
 */
export const SettingsPaymentsMethods = () => {
	const {
		groupedProviders = {},
		gatewayPlugins = {},
		providerIcons = {},
		methodIcons = {},
		providerAssets = {},
	} = getSetting< SpikeSettings >( 'blocksPaymentMethodsSpike', {} );

	const { updatePaymentMethodOrder } = useDispatch( paymentSettingsStore );
	const { createSuccessNotice } = useDispatch( 'core/notices' );

	// The registry is populated synchronously by the payment method scripts, which run before this
	// bundle. Read it once so the list does not change under the user.
	//
	// `savedRows` reflect the persisted order (the canonical order when present, otherwise the
	// default/fallback) and are both the initial state and the baseline for dirty detection.
	const savedRows = useMemo( () => {
		const registered = Object.values( getPaymentMethods() );
		const sortOrder = getSetting< string[] >(
			'paymentMethodSortOrder',
			[]
		);

		return buildRows( registered, groupedProviders, sortOrder );
	}, [ groupedProviders ] );

	const [ rows, setRows ] = useState( savedRows );
	// The persisted order the current rows are compared against. Updated only after a successful save,
	// so the page's dirty state never diverges from what is actually stored.
	const [ savedOrder, setSavedOrder ] = useState( () =>
		serializePaymentMethodOrder( savedRows )
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ isNoticeDismissed, setIsNoticeDismissed ] = useState( false );

	const isDirty = ! ordersEqual(
		serializePaymentMethodOrder( rows ),
		savedOrder
	);

	const handleSave = async () => {
		setIsSaving( true );
		setError( null );

		const order = serializePaymentMethodOrder( rows );

		try {
			const result = ( await updatePaymentMethodOrder( order ) ) as
				| { success: boolean }
				| undefined;

			if ( ! result?.success ) {
				throw new Error( 'Saving the payment method order failed.' );
			}

			// Only adopt the new baseline once the server confirms the write.
			setSavedOrder( order );

			createSuccessNotice(
				__( 'Payment method order saved.', 'woocommerce' ),
				{ type: 'snackbar' }
			);
		} catch {
			setError(
				__(
					'The payment method order could not be saved. Please try again.',
					'woocommerce'
				)
			);
		} finally {
			setIsSaving( false );
		}
	};

	const pluginSlugFor = ( paymentMethod: RegisteredPaymentMethod ) =>
		gatewayPlugins[ gatewayIdOf( paymentMethod ) ];

	// The trailing badge names the provider, so it is matched on the owning plugin rather than on
	// the method id, and it draws from the rectangle set. The plugin's own logo, from the payments
	// providers service, stays as the fallback: WooPayments has no bundled asset, and its own badge
	// is the right one anyway.
	const providerIconFor = ( paymentMethod: RegisteredPaymentMethod ) => {
		const plugin = pluginSlugFor( paymentMethod );

		return (
			bundledIconSrc( plugin ?? '', providerAssets, {
				preferLeading: true,
			} ) ?? providerIcons[ plugin ]
		);
	};

	const moveToTop = ( id: string ) =>
		setRows( ( current ) => [
			...current.filter( ( row ) => row.id === id ),
			...current.filter( ( row ) => row.id !== id ),
		] );

	// Stripe recommends being first when Optimized Checkout is on, since it is then the only
	// method rendering a full payment element.
	const optimizedCheckoutRow = rows.find(
		( row ) => row.group?.optimizedCheckout
	);
	const shouldPromptMoveToTop =
		! isNoticeDismissed &&
		optimizedCheckoutRow !== undefined &&
		rows[ 0 ]?.id !== optimizedCheckoutRow.id;

	if ( rows.length === 0 ) {
		return (
			<div className="settings-payments-main__container settings-payments-methods">
				<p>
					{ __(
						'No Checkout Block payment methods are registered. Enable at least one payment method that supports the Checkout Block.',
						'woocommerce'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="settings-payments-main__container settings-payments-methods">
			<div className="settings-payment-gateways">
				<div className="settings-payment-gateways__header">
					<div className="settings-payment-gateways__header-title">
						<BackButton
							href={ getAdminLink(
								'admin.php?page=wc-settings&tab=checkout'
							) }
						/>
						{ __( 'Reorder payment methods', 'woocommerce' ) }
					</div>
					<div className="settings-payments-methods__actions">
						<Button
							variant="primary"
							onClick={ handleSave }
							isBusy={ isSaving }
							disabled={ ! isDirty || isSaving }
						>
							{ __( 'Save', 'woocommerce' ) }
						</Button>
					</div>
				</div>
				{ error && (
					<Notice
						className="settings-payments-methods__error"
						status="error"
						onRemove={ () => setError( null ) }
					>
						{ error }
					</Notice>
				) }
				<SortableContainer< Row >
					items={ rows }
					className="settings-payment-gateways__list"
					setItems={ setRows }
				>
					{ rows.map( ( row ) => (
						<SortableItem
							key={ row.id }
							id={ row.id }
							className={ clsx( {
								'settings-payments-methods__group':
									row.children.length ||
									( row.group?.optimizedCheckout &&
										shouldPromptMoveToTop ),
								// Only a group that actually lists methods tightens up; the one
								// that only carries the notice keeps the normal row rhythm.
								'settings-payments-methods__group--nested':
									row.children.length > 0,
							} ) }
						>
							<div
								id={ row.id }
								className="transitions-disabled woocommerce-list__item woocommerce-list__item-enter-done woocommerce-item__payment-gateway"
							>
								<div className="woocommerce-list__item-inner">
									<div className="woocommerce-list__item-before">
										<DefaultDragHandle />
										{ /* A row that heads a nested list stands over its
										     methods rather than beside them, so it carries no
										     logo of its own. With Optimized Checkout on there
										     are no children to head and it is just the provider,
										     which does want its logo. */ }
										{ row.children.length === 0 && (
											<MethodIcon
												paymentMethod={
													row.paymentMethod
												}
												icons={ methodIcons }
												pluginSlug={ pluginSlugFor(
													row.paymentMethod
												) }
											/>
										) }
									</div>
									<div className="woocommerce-list__item-text">
										<span className="woocommerce-list__item-title">
											<PaymentMethodName
												paymentMethod={
													row.paymentMethod
												}
												isProvider={ Boolean(
													row.group
												) }
											/>
											{ row.group?.optimizedCheckout && (
												<StatusBadge
													status="inactive"
													message={ __(
														'Using Optimized Checkout',
														'woocommerce'
													) }
												/>
											) }
										</span>
									</div>
									<div className="woocommerce-list__item-after centered no-buttons">
										<div className="woocommerce-list__item-after__actions">
											{ row.group &&
												! row.group
													.optimizedCheckout && (
													<ExternalLink
														href={
															row.group
																.settingsUrl
														}
													>
														{ __(
															"Reorder Stripe's payment methods",
															'woocommerce'
														) }
													</ExternalLink>
												) }
											<ProviderIcon
												src={ providerIconFor(
													row.paymentMethod
												) }
											/>
										</div>
									</div>
								</div>
							</div>
							{ row.group?.optimizedCheckout &&
								shouldPromptMoveToTop && (
									<Notice
										className="settings-payments-methods__notice"
										status="warning"
										onRemove={ () =>
											setIsNoticeDismissed( true )
										}
									>
										<p>
											{ __(
												'Optimized Checkout works best when Stripe is your first payment method.',
												'woocommerce'
											) }
										</p>
										<Button
											variant="secondary"
											onClick={ () =>
												moveToTop( row.id )
											}
										>
											{ __(
												'Move to top',
												'woocommerce'
											) }
										</Button>
									</Notice>
								) }
							{ row.children.map( ( child ) => (
								<div
									key={ child.name }
									id={ gatewayIdOf( child ) }
									className="transitions-disabled woocommerce-list__item woocommerce-list__item-enter-done woocommerce-item__payment-gateway settings-payments-methods__child"
								>
									<div className="woocommerce-list__item-inner">
										<div className="woocommerce-list__item-before">
											<DragHandleSpacer />
											{ /* Nested rows use the rectangle artwork. */ }
											<MethodIcon
												paymentMethod={ child }
												icons={ providerAssets }
												pluginSlug={ pluginSlugFor(
													child
												) }
												shape="rectangle"
											/>
										</div>
										<div className="woocommerce-list__item-text">
											<span className="woocommerce-list__item-title">
												<PaymentMethodName
													paymentMethod={ child }
												/>
											</span>
										</div>
									</div>
								</div>
							) ) }
						</SortableItem>
					) ) }
				</SortableContainer>
			</div>
		</div>
	);
};

export default SettingsPaymentsMethods;
