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
 * `icon` and `label` are pre-rendered by the page (which owns the payment-method registry and icon
 * maps), so the modal stays presentational.
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
