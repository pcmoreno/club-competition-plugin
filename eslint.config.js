// The @wordpress/scripts default, plus `x != null` as the null-or-undefined idiom.
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		rules: {
			eqeqeq: [ 'error', 'always', { null: 'ignore' } ],
		},
	},
];
