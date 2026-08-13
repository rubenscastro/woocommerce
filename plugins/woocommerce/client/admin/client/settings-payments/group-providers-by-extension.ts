/**
 * External dependencies
 */
import {
	PaymentsProviderType,
	type PaymentsProvider,
	type PaymentGatewayProvider,
	type SuggestedPaymentsExtension,
} from '@woocommerce/data';
import { getQueryArg } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { removeOriginFromURL } from '~/settings-payments/utils';

/**
 * A group of payment-provider rows that all belong to the same extension.
 *
 * The parent row is a presentation-only header for the extension; the children are the real gateway
 * provider rows the extension registered.
 */
export type ProviderGroup = {
	/**
	 * Stable key for the group — the extension's suggestion id or plugin slug. Used as the React key,
	 * the expand-state key, and the `aria-controls` target. Never persisted.
	 */
	id: string;
	/**
	 * The extension's suggestion id, when it has one. Present for known Payments extensions and drives
	 * the "Official" badge on the parent row.
	 */
	suggestionId?: string;
	/**
	 * The extension's display name, shown on the parent row.
	 */
	title: string;
	/**
	 * The extension's logo, shown on the parent row.
	 */
	icon?: string;
	/**
	 * The gateway provider rows that belong to the extension, in their original list order.
	 */
	children: PaymentGatewayProvider[];
	/**
	 * The settings URL shared by every child, when they all point at the same one. When present the
	 * parent row shows a single Manage button and the children hide theirs; when absent each child
	 * keeps its own Manage button.
	 */
	sharedSettingsUrl?: string;
};

/**
 * A single item to render in the providers list: either a standalone provider row, or an extension
 * group that nests several gateway rows.
 */
export type ProviderListItem =
	| { type: 'provider'; provider: PaymentsProvider }
	| { type: 'group'; group: ProviderGroup };

/**
 * The key that groups gateway rows by extension.
 *
 * The suggestion id is the most reliable signal — every gateway an extension registers resolves to
 * the same one — with the plugin slug (the plugin directory) as a fallback. The provider id is the
 * last resort so a gateway with neither can never merge into an unrelated group.
 */
const groupKeyOf = ( provider: PaymentsProvider ): string =>
	provider._suggestion_id || provider.plugin?.slug || provider.id;

/**
 * A readable extension name derived from a plugin slug, used only when no suggestion is available.
 */
const humanizePluginSlug = ( slug?: string ): string | undefined => {
	if ( ! slug ) {
		return undefined;
	}

	const cleaned = slug
		.replace( /^woocommerce-gateway-/, '' )
		.replace( /^woocommerce-/, '' )
		.replace( /-/g, ' ' )
		.trim();

	if ( ! cleaned ) {
		return undefined;
	}

	return cleaned.replace( /\b\w/g, ( character ) => character.toUpperCase() );
};

/**
 * A stable identity for a settings URL: the admin page it opens, ignoring transient query args.
 *
 * WooCommerce builds a gateway's settings URL from its `page`/`tab`/`section` and tacks on a `from`
 * marker (and the origin) that varies without changing which settings screen opens. Comparing those
 * would wrongly split extensions whose gateways all manage from one screen — PayPal is one — so the
 * key is just the meaningful parts. Non-standard URLs (no `page`/`tab`/`section`) fall back to the
 * origin-stripped URL so custom settings pages are still compared exactly.
 */
const settingsKeyOf = ( href: string ): string => {
	const page = getQueryArg( href, 'page' );
	const tab = getQueryArg( href, 'tab' );
	const section = getQueryArg( href, 'section' );

	if ( page || tab || section ) {
		return [ page ?? '', tab ?? '', section ?? '' ].join( '|' );
	}

	return removeOriginFromURL( href );
};

/**
 * The settings URL shared by every child, or `undefined` when they open different settings screens or
 * any is missing. "Shared" is judged by the settings screen the URL opens, not the exact string.
 */
const sharedSettingsUrlOf = (
	children: PaymentGatewayProvider[]
): string | undefined => {
	const hrefs = children.map(
		( child ) => child.management?._links?.settings?.href
	);

	if ( hrefs.some( ( href ) => ! href ) ) {
		return undefined;
	}

	const keys = hrefs.map( ( href ) => settingsKeyOf( href as string ) );

	return new Set( keys ).size === 1 ? hrefs[ 0 ] : undefined;
};

const buildGroup = (
	id: string,
	children: PaymentGatewayProvider[],
	suggestionsById: Record< string, SuggestedPaymentsExtension >
): ProviderGroup => {
	const first = children[ 0 ];
	const suggestion = first._suggestion_id
		? suggestionsById[ first._suggestion_id ]
		: undefined;

	return {
		id,
		suggestionId: first._suggestion_id,
		title:
			suggestion?.title ||
			humanizePluginSlug( first.plugin?.slug ) ||
			first.title,
		icon: suggestion?.icon || first.icon,
		children,
		sharedSettingsUrl: sharedSettingsUrlOf( children ),
	};
};

/**
 * Group the providers list so gateway rows from the same extension nest under one parent.
 *
 * Only `Gateway` providers are grouped, and only when an extension contributes two or more of them —
 * a single-gateway extension (e.g. WooPayments) stays a normal row. Suggestions and the offline group
 * always pass through untouched. Grouping is presentation-only: it reads no ordering state and writes
 * nothing. Each group is emitted at the position of its first child (the backend already sorted the
 * list by `_order`), and any later siblings are pulled up into it, so an extension whose rows were
 * interleaved with others is gathered together.
 *
 * @param providers       The providers list from the settings-payments store, already ordered.
 * @param suggestionsById The payment-extension suggestions keyed by id, for the parent title/icon.
 *
 * @return The list to render, with grouped extensions collapsed into a single group item.
 */
export const groupProvidersByExtension = (
	providers: PaymentsProvider[],
	suggestionsById: Record< string, SuggestedPaymentsExtension > = {}
): ProviderListItem[] => {
	const buckets = new Map< string, PaymentGatewayProvider[] >();

	for ( const provider of providers ) {
		if ( provider._type !== PaymentsProviderType.Gateway ) {
			continue;
		}

		const key = groupKeyOf( provider );
		const bucket = buckets.get( key ) ?? [];
		bucket.push( provider as PaymentGatewayProvider );
		buckets.set( key, bucket );
	}

	const emitted = new Set< string >();
	const items: ProviderListItem[] = [];

	for ( const provider of providers ) {
		if ( provider._type === PaymentsProviderType.Gateway ) {
			const key = groupKeyOf( provider );
			const bucket = buckets.get( key );

			if ( bucket && bucket.length >= 2 ) {
				if ( emitted.has( key ) ) {
					continue;
				}

				emitted.add( key );
				items.push( {
					type: 'group',
					group: buildGroup( key, bucket, suggestionsById ),
				} );
				continue;
			}
		}

		items.push( { type: 'provider', provider } );
	}

	return items;
};
