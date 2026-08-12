/* global jest */
/**
 * Test stub for `@woocommerce/blocks-registry`.
 *
 * That package lives in the WooCommerce Blocks client and is a webpack external at build time, so
 * it has no resolvable module under the admin client in unit tests. This stub stands in for it;
 * tests control the return value of `getPaymentMethods` via its jest mock.
 */
module.exports = {
	getPaymentMethods: jest.fn( () => ( {} ) ),
};
