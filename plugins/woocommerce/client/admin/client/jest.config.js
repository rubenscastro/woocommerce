const path = require( 'path' );

// Require the preset by absolute path so the package's `exports` field (which does not expose the
// preset subpath) does not block it, while still letting us reorder its moduleNameMapper below.
const preset = require( path.resolve(
	__dirname,
	'../node_modules/@woocommerce/internal-js-tests/jest-preset.js'
) );

module.exports = {
	...preset,
	rootDir: '../',
	roots: [ '<rootDir>/client' ],
	moduleNameMapper: {
		// `@woocommerce/blocks-registry` lives in the WooCommerce Blocks client and is a webpack
		// external at build time, so it has no resolvable module in the admin client's unit tests.
		// Map it to a lightweight stub (tests mock its exports as needed). This entry must precede
		// the preset's generic `@woocommerce/*` mapping to take effect.
		'^@woocommerce/blocks-registry$': path.resolve(
			__dirname,
			'settings-payments/blocks-registry-stub.js'
		),
		...preset.moduleNameMapper,
	},
};
