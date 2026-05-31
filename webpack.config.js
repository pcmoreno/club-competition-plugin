/**
 * Extends the @wordpress/scripts default webpack config with two entry points.
 *
 *   js/viewer/index.js → build/viewer.js  + build/viewer.asset.php  (+ viewer.css)
 *   js/admin/index.js  → build/admin.js   + build/admin.asset.php   (+ admin.css)
 *
 * Everything else (Babel, PostCSS/Tailwind, CSS extraction, asset manifest,
 * React externals) is inherited from the default config so we stay aligned
 * with WordPress' tooling.
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
  ...defaultConfig,
  entry: {
    viewer: './js/viewer/index.js',
    admin: './js/admin/index.js',
  },
};
