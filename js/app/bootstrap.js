/**
 * Reads the server-injected bootstrap payload (see Assets::enqueue_frontend,
 * which calls wp_localize_script for `window.scsBootstrap`). Falls back to safe
 * defaults so the bundle still mounts when opened outside WordPress (e.g. a
 * Storybook/dev harness).
 *
 * Everything here is the same for every visitor, deliberately: this payload is
 * written into the page HTML, which a full-page cache hands to the next person.
 * The session comes from cookies instead — see auth/sessionHint.js.
 */
const raw = typeof window !== 'undefined' ? window.scsBootstrap : undefined;

export const bootstrap = {
	// REST base, e.g. "https://site/wp-json/scs/v1/" (trailing slash).
	apiRoot: raw?.apiRoot ?? '/wp-json/scs/v1/',
	// URL of the plugin's build/ dir, for any runtime asset references.
	buildUrl: raw?.buildUrl ?? '',
};
