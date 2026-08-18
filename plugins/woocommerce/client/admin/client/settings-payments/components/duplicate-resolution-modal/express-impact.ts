/**
 * Internal dependencies
 */
import type { ExpressControlUnit, ExpressDuplicateGroup } from './types';

/**
 * A wallet lost as a side effect of a choice the merchant did not make directly.
 */
export type ExpressConsequence = {
	walletId: string;
	causedByUnitId: string;
	providerLabel: string;
};

/**
 * A choice contradicted by another choice: the provider picked for this wallet gets turned off.
 */
export type ExpressConflict = {
	walletId: string;
	unitId: string;
};

export type ExpressImpact = {
	disable: string[];
	lostWallets: string[];
	consequences: ExpressConsequence[];
	conflicts: ExpressConflict[];
	blocked: string[];
};

/**
 * Whether a unit is the one the merchant chose.
 *
 * A selection may name either the provider slug or the control unit id.
 */
const matchesSelection = ( unit: ExpressControlUnit, chosen: string ) =>
	chosen === unit.id || chosen === unit.providerSlug;

const providesWallet = ( unit: ExpressControlUnit, walletId: string ) =>
	unit.wallets.includes( walletId );

const walletsProvidedBy = ( units: ExpressControlUnit[] ) => {
	const wallets = new Set< string >();
	units.forEach( ( unit ) =>
		unit.wallets.forEach( ( wallet ) => wallets.add( wallet ) )
	);
	return wallets;
};

/**
 * Work out what a set of wallet → provider selections would actually do.
 *
 * This mirrors `ExpressImpactCalculator` on the server, deliberately. The modal needs the answer
 * immediately as the merchant changes a selection, and the server needs to recompute it at apply
 * time from freshly detected state rather than trusting whatever the client sent. The server's
 * result is the authoritative one; this exists only so the preview is instant.
 *
 * Keep the two in step: the scenarios in this file's tests mirror `ExpressImpactCalculatorTest`.
 *
 * @param units      All known control units.
 * @param selections Chosen provider (slug or control unit id) keyed by wallet id.
 */
export const calculateExpressImpact = (
	units: ExpressControlUnit[],
	selections: Record< string, string >
): ExpressImpact => {
	const enabled = units.filter( ( unit ) => unit.enabled );
	const chosen = Object.entries( selections ).filter(
		( [ walletId, value ] ) => walletId && value
	);

	const availableBefore = walletsProvidedBy( enabled );

	const toDisable = enabled.filter( ( unit ) =>
		chosen.some(
			( [ walletId, value ] ) =>
				providesWallet( unit, walletId ) &&
				! matchesSelection( unit, value )
		)
	);
	const disableIds = new Set( toDisable.map( ( unit ) => unit.id ) );
	const remaining = enabled.filter( ( unit ) => ! disableIds.has( unit.id ) );

	const availableAfter = walletsProvidedBy( remaining );
	const lostWallets = [ ...availableBefore ].filter(
		( wallet ) => ! availableAfter.has( wallet )
	);

	// A wallet the merchant actively reassigned is the request, not collateral damage.
	const consequences: ExpressConsequence[] = [];
	lostWallets.forEach( ( walletId ) => {
		if ( selections[ walletId ] ) {
			return;
		}

		const cause = toDisable.find( ( unit ) =>
			providesWallet( unit, walletId )
		);

		if ( cause ) {
			consequences.push( {
				walletId,
				causedByUnitId: cause.id,
				providerLabel: cause.providerLabel,
			} );
		}
	} );

	const conflicts: ExpressConflict[] = [];
	chosen.forEach( ( [ walletId, value ] ) => {
		const contradicted = toDisable.find(
			( unit ) =>
				providesWallet( unit, walletId ) &&
				matchesSelection( unit, value )
		);

		if ( contradicted ) {
			conflicts.push( { walletId, unitId: contradicted.id } );
		}
	} );

	return {
		disable: [ ...disableIds ],
		lostWallets,
		consequences,
		conflicts,
		blocked: toDisable
			.filter( ( unit ) => ! unit.canDisable )
			.map( ( unit ) => unit.id ),
	};
};

/**
 * Turn one-choice-per-group selections into the per-method form the impact model works in.
 *
 * A method is only treated as *requested* when the chosen provider actually offers it. That is what
 * keeps a group's uncovered methods showing up as losses: the merchant asked for the provider, not
 * for losing the rest, so the rest is still collateral and still reported.
 *
 * @param groups     The decision groups.
 * @param selections Chosen provider slug keyed by group id.
 */
export const expandGroupSelections = (
	groups: ExpressDuplicateGroup[],
	selections: Record< string, string >
): Record< string, string > => {
	const expanded: Record< string, string > = {};

	groups.forEach( ( group ) => {
		const providerSlug = selections[ group.id ];

		if ( ! providerSlug ) {
			return;
		}

		const option = group.options.find(
			( candidate ) => candidate.providerSlug === providerSlug
		);

		option?.covers.forEach( ( walletId ) => {
			expanded[ walletId ] = providerSlug;
		} );
	} );

	return expanded;
};

/**
 * What turning off a set of gateways would cost in express methods.
 *
 * Express methods are frequently served off the back of another of the provider's methods — Stripe
 * refuses to disable Card while its wallets are on, Square simply stops registering them — so
 * resolving a *regular* duplicate can silently take express methods with it. This answers what would
 * go, and what would survive elsewhere, before the merchant commits.
 *
 * @param units              All known control units.
 * @param disabledGatewayIds The gateway ids about to be turned off.
 */
export const calculatePrerequisiteImpact = (
	units: ExpressControlUnit[],
	disabledGatewayIds: string[]
): {
	brokenUnits: ExpressControlUnit[];
	lostMethods: string[];
	survivingMethods: string[];
	survivingVia: string[];
} => {
	const enabled = units.filter( ( unit ) => unit.enabled );

	// `requires` is defensive: a payload cached from before prerequisites existed would not carry it,
	// and a unit with no prerequisite simply cannot be broken this way.
	const brokenUnits = enabled.filter( ( unit ) =>
		( unit.requires ?? [] ).some( ( required ) =>
			disabledGatewayIds.includes( required )
		)
	);
	const brokenIds = new Set( brokenUnits.map( ( unit ) => unit.id ) );
	const surviving = enabled.filter( ( unit ) => ! brokenIds.has( unit.id ) );

	const stillOffered = walletsProvidedBy( surviving );
	const affected = new Set< string >();
	brokenUnits.forEach( ( unit ) =>
		unit.wallets.forEach( ( wallet ) => affected.add( wallet ) )
	);

	return {
		brokenUnits,
		// Gone entirely — no other provider still offers them.
		lostMethods: [ ...affected ].filter(
			( wallet ) => ! stillOffered.has( wallet )
		),
		// Still offered, just by somebody else. This is what lets the notice reassure rather than
		// only warn — and it is computed, never assumed.
		survivingMethods: [ ...affected ].filter( ( wallet ) =>
			stillOffered.has( wallet )
		),
		// Who is left offering them. Derived rather than assumed to be the provider the merchant
		// just consolidated onto: that provider may not offer these methods at all.
		survivingVia: [
			...new Set(
				surviving
					.filter( ( unit ) =>
						unit.wallets.some( ( wallet ) =>
							affected.has( wallet )
						)
					)
					.map( ( unit ) => unit.providerLabel )
			),
		],
	};
};
