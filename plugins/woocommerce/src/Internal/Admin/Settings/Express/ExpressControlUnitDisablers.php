<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\Express;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an express control unit off through whichever integration owns it.
 *
 * Shared by both resolvers, because express units get turned off for two different reasons: because
 * the merchant chose another provider for that method, and because something the unit depends on is
 * being turned off. The second case is why this is not private to the express resolver.
 *
 * @internal
 *
 * @since 11.1.0
 */
class ExpressControlUnitDisablers {

	/**
	 * Turn one control unit off.
	 *
	 * @param string $unit_id The control unit id.
	 *
	 * @return array{status: string, message?: string} `status` is `disabled`, `failed`, or
	 *         `unsupported` when no integration claims the unit.
	 */
	public function disable( string $unit_id ): array {
		foreach ( $this->get_disablers() as $disabler ) {
			try {
				if ( ! $disabler->supports( $unit_id ) ) {
					continue;
				}

				$outcome = $disabler->disable( $unit_id );
			} catch ( Throwable $e ) {
				return array(
					'status'  => 'failed',
					'message' => $e->getMessage(),
				);
			}

			return $this->format_outcome( $outcome );
		}

		// No integration claims the unit. Core deliberately has no fallback: express control
		// surfaces are provider-specific, and a generic guess would write settings core does not
		// understand.
		return array(
			'status'  => 'unsupported',
			'message' => 'No integration can turn this express method off.',
		);
	}

	/**
	 * Coerce a third-party outcome into the documented shape.
	 *
	 * @param mixed $outcome The value the disabler returned.
	 *
	 * @return array{status: string, message?: string}
	 */
	private function format_outcome( $outcome ): array {
		if ( ! is_array( $outcome ) || ! isset( $outcome['status'] ) || 'disabled' !== $outcome['status'] ) {
			return array(
				'status'  => 'failed',
				'message' => is_array( $outcome ) && isset( $outcome['message'] ) ? (string) $outcome['message'] : '',
			);
		}

		return array( 'status' => 'disabled' );
	}

	/**
	 * The registered control-unit disablers.
	 *
	 * @return ExpressControlUnitDisablerInterface[]
	 */
	private function get_disablers(): array {
		$core_disablers = array(
			new WooPaymentsExpressAdapter(),
			new PayPalExpressAdapter(),
		);

		/**
		 * Filters the integrations that can turn an express control unit off.
		 *
		 * Each entry must implement
		 * {@see \Automattic\WooCommerce\Internal\Admin\Settings\Express\ExpressControlUnitDisablerInterface}.
		 * Core seeds the integrations whose settings APIs it has verified; an extension registering
		 * later takes precedence for the units it claims.
		 *
		 * @param ExpressControlUnitDisablerInterface[] $disablers Registered disabler instances.
		 *
		 * @since 11.1.0
		 */
		$filtered = apply_filters( 'woocommerce_express_checkout_control_unit_disablers', $core_disablers );

		return $this->normalize( $filtered, $core_disablers );
	}

	/**
	 * Coerce a filtered disabler list into usable instances.
	 *
	 * @param mixed                                 $filtered       The filtered value.
	 * @param ExpressControlUnitDisablerInterface[] $core_disablers The disablers core registered.
	 *
	 * @return ExpressControlUnitDisablerInterface[]
	 */
	private function normalize( $filtered, array $core_disablers ): array {
		if ( ! is_array( $filtered ) ) {
			return $core_disablers;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $disabler ) {
					return $disabler instanceof ExpressControlUnitDisablerInterface;
				}
			)
		);
	}
}
