/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * A provider the merchant can keep for a given duplicated method.
 *
 * Produced server-side (see `BlocksPaymentMethodsSpikeController::get_duplicate_providers`) so provider
 * identity and labels are never inferred on the client.
 */
export type DuplicateProviderOption = {
	gatewayId: string;
	providerSlug: string;
	providerLabel: string;
	providerIcon: string;
	// Whether this implementation can be disabled individually. An implementation that cannot be
	// disabled (e.g. WooPayments Card, which is required while WooPayments is active) is never a valid
	// "keep the other one" choice.
	canDisable: boolean;
};

/**
 * A single resolvable regular duplicate, ready to render.
 *
 * `icon` and `label` are pre-rendered so the modal stays presentational.
 *
 * `requiredKeepGatewayId` is set by the server when exactly one implementation cannot be disabled: that
 * implementation is the only valid keep, so the modal preselects it and locks the control.
 */
export type DuplicateResolutionRow = {
	canonicalId: string;
	icon: ReactNode;
	label: ReactNode;
	options: DuplicateProviderOption[];
	requiredKeepGatewayId: string | null;
};

/**
 * The server-produced candidate for one resolvable regular duplicate, keyed by canonical method id in
 * the `duplicateProviders` payload (see `BlocksPaymentMethodsSpikeController::get_duplicate_providers`).
 *
 * `methodLabel` and `methodIcon` describe the payment *method* (the row's identity — e.g. the generic
 * Card icon for `card`, never a provider logo). They are resolved server-side so the row can be built
 * without the client-side payment-method registry, which the Payment providers page does not load.
 */
export type DuplicateProvidersCandidate = {
	methodLabel: string;
	methodIcon: string;
	implementations: DuplicateProviderOption[];
	requiredKeepGatewayId: string | null;
};

/**
 * The `duplicateProviders` payload: one candidate per resolvable regular duplicate, keyed by canonical
 * method id.
 */
export type DuplicateProviders = Record< string, DuplicateProvidersCandidate >;

/**
 * The detected duplicate groups the server publishes: enabled gateway ids per canonical method id,
 * split into regular and express buckets.
 */
export type DuplicateGroups = {
	payment_methods?: Record< string, string[] >;
	express?: Record< string, string[] >;
};

/**
 * A provider the merchant can choose for a group of express methods.
 *
 * The unit of choice is a **provider**, not a gateway and not a control unit: a provider may offer
 * its express methods through several gateways or several independently controllable units, and
 * those are one decision to the merchant ("keep PayPal"), not several.
 *
 * `covers` is the subset of the group's methods this provider actually offers *right now*. Where
 * that is less than the whole group, the difference is exactly what choosing it will disable.
 *
 * `supportsDisabled` splits that difference in two. A method listed there is one the provider does
 * offer and the merchant has switched off; anything else in the difference is one the provider does
 * not offer at all. Both get turned off by the choice, but only the first can be kept — by enabling
 * it in that provider's settings — so the two cannot be described to the merchant in the same words.
 */
export type ExpressProviderOption = {
	providerSlug: string;
	providerLabel: string;
	providerIcon: string;
	controlUnitIds: string[];
	covers: string[];
	supportsDisabled: string[];
	canDisable: boolean;
};

/**
 * One decision the merchant makes about duplicated express methods.
 *
 * A group holds every method that must be decided together — methods a single provider setting
 * binds cannot be given to different providers, so they are presented as one choice rather than
 * offering a combination that cannot exist.
 */
export type ExpressDuplicateGroup = {
	id: string;
	walletIds: string[];
	label: string;
	icons: string[];
	options: ExpressProviderOption[];
};

/**
 * The `expressDuplicates` payload: one entry per decision.
 */
export type ExpressDuplicates = ExpressDuplicateGroup[];

/**
 * A control unit as published in the `expressControlUnits` payload.
 *
 * The full graph of what can be turned off and which methods each unit carries. The modal needs the
 * whole graph — not just the units involved in a duplicate — because turning one off can affect a
 * method that is not itself duplicated.
 */
export type ExpressControlUnit = {
	id: string;
	providerSlug: string;
	providerLabel: string;
	gatewayIds: string[];
	wallets: string[];
	enabled: boolean;
	canDisable: boolean;
	// Gateway ids that must stay enabled for this unit to work. A provider often serves its express
	// methods off the back of another of its methods, so turning that off takes the wallets too.
	requires: string[];
};
