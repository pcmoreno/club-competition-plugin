/**
 * Reads the readable `scs_ui` session cookie the server sets alongside the
 * httpOnly JWT (see AuthController::setSessionCookies).
 *
 * It exists so the app knows who it is at first paint without inlining the
 * session into the page HTML, which a full-page cache would then serve to every
 * later visitor. A cookie is per-browser, so no cache can copy it across.
 *
 * Treat the result as a display hint, never as proof: it is readable and
 * writable by anything running on the page. Every request is still authorized
 * by the httpOnly token, so a forged hint yields chrome the API refuses to
 * honour, and the real session is confirmed by GET /auth/me on mount.
 */
export function readSessionHint() {
	if ( typeof document === 'undefined' ) {
		return null;
	}

	const raw = document.cookie
		.split( '; ' )
		.find( ( entry ) => entry.startsWith( 'scs_ui=' ) )
		?.slice( 'scs_ui='.length );

	if ( ! raw ) {
		return null;
	}

	try {
		const parsed = JSON.parse( decodeURIComponent( raw ) );
		const role = parsed?.role;
		if ( role !== 'ROLE_MEMBER' && role !== 'ROLE_ADMIN' ) {
			return null;
		}
		return { role, playerId: parsed.pid ?? null };
	} catch {
		// A malformed cookie just means "unknown" — the session check that
		// follows is what actually decides.
		return null;
	}
}
