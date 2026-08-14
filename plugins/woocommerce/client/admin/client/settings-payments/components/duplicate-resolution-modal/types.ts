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
