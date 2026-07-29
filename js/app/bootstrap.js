/**
 * Reads the server-injected bootstrap payload (see Assets::enqueue_frontend,
 * which calls wp_localize_script for `window.scsBootstrap`). Falls back to safe
 * defaults so the bundle still mounts when opened outside WordPress (e.g. a
 * Storybook/dev harness).
 */
const raw = typeof window !== 'undefined' ? window.scsBootstrap : undefined;

export const bootstrap = {
	// REST base, e.g. "https://site/wp-json/scs/v1/" (trailing slash).
	apiRoot: raw?.apiRoot ?? '/wp-json/scs/v1/',
	// 'ROLE_ADMIN' | 'ROLE_MEMBER' | null — resolved server-side from the
	// httpOnly scs_token JWT cookie at render time.
	role: raw?.role ?? null,
	// The logged-in member's player id (null for anonymous/admins), used to
	// identify "you" in lists.
	playerId: raw?.playerId ?? null,
	// The signed-in user's own email (null for anonymous), shown in the
	// top-bar account menu.
	email: raw?.email ?? null,
	// URL of the plugin's build/ dir, for any runtime asset references.
	buildUrl: raw?.buildUrl ?? '',
};
