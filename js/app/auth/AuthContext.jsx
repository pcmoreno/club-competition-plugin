import {
	createContext,
	useContext,
	useState,
	useCallback,
	useEffect,
} from '@wordpress/element';
import { useQueryClient } from '@tanstack/react-query';
import { api, setCsrfToken, setUnauthenticatedHandler } from '../api/client';
import { readSessionHint } from './sessionHint';
import { keys } from '../api/keys';
import { navigate } from '../router/router';

const AuthContext = createContext( null );

// Holds the session role and the CSRF token used for writes (admin writes,
// plus login/logout, which need it even when signed out).
//
// Role is seeded from the readable scs_ui cookie, so a signed-in visitor is
// recognised on first paint without a round-trip and without the session ever
// entering the page HTML, where a full-page cache would pick it up and serve it
// to the next visitor (see Assets::enqueue_frontend). The hint is display-only
// and forgeable; GET /auth/me then confirms it against the httpOnly JWT, which
// is what actually authorizes anything.
//
// The CSRF token isn't known at render either (issuing one would mutate the
// cookie on a GET), so it's fetched lazily on mount.
export function AuthProvider( { children } ) {
	// AuthProvider sits inside QueryClientProvider (see App.jsx), so the cache
	// can be dropped whenever the identity behind it changes.
	const queryClient = useQueryClient();
	const [ hint ] = useState( readSessionHint );
	const [ role, setRole ] = useState( hint?.role ?? null );
	// The logged-in member's player id (null for anonymous/admins), used to
	// identify "you" in lists.
	const [ playerId, setPlayerId ] = useState( hint?.playerId ?? null );
	// The signed-in user's own email, shown in the top-bar account menu. Not in
	// the hint — it's the one piece of PII here, and the account menu falls back
	// to "Account" until /auth/me answers.
	const [ email, setEmail ] = useState( null );

	const isMember = role === 'ROLE_MEMBER' || role === 'ROLE_ADMIN';
	const isAdmin = role === 'ROLE_ADMIN';

	const refreshCsrf = useCallback( async () => {
		try {
			const { csrf_token: csrfToken } =
				await api.get( 'auth/csrf-token' );
			setCsrfToken( csrfToken );
		} catch {
			setCsrfToken( null );
		}
	}, [] );

	// Fetch a CSRF token on load regardless of auth state: GET /auth/csrf-token
	// is public, and login/logout now require the header too (not just admin
	// writes), so an anonymous visitor needs one before their first login POST.
	useEffect( () => {
		refreshCsrf();
	}, [ refreshCsrf ] );

	// Confirm the hint against the server, and pick up the email while we're
	// there. Only when the hint claims a session: an anonymous visitor has
	// nothing to confirm and shouldn't pay for a request.
	//
	// Routed through the query cache under the key the Account page uses, so
	// landing there costs no second request. A 401 means the hint outlived its
	// token (revoked, or signed out in another tab); the shared handler below
	// clears the session and redirects, which is exactly right here.
	useEffect( () => {
		if ( ! hint ) {
			return undefined;
		}
		let cancelled = false;
		queryClient
			.fetchQuery( {
				queryKey: keys.account(),
				queryFn: () => api.get( 'auth/me' ),
				retry: false,
			} )
			.then( ( me ) => {
				if ( cancelled || ! me ) {
					return;
				}
				// The server is authoritative — a hand-edited hint gets corrected here.
				setRole( me.role ?? null );
				setPlayerId( me.player?.id ?? null );
				setEmail( me.email ?? null );
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [ hint, queryClient ] );

	// The JWT can expire mid-session. Without this the chrome would keep
	// offering a session that no longer exists while every query fails on its
	// own.
	useEffect( () => {
		setUnauthenticatedHandler( () => {
			// Read through the setter: a 401 for someone who was never signed in
			// is just a public view touching a gated route, and bouncing an
			// anonymous visitor to /login would be wrong.
			setRole( ( current ) => {
				if ( current === null ) {
					return current;
				}
				setPlayerId( null );
				setEmail( null );
				queryClient.clear();
				refreshCsrf();
				navigate( '/login' );
				return null;
			} );
		} );
		return () => setUnauthenticatedHandler( null );
	}, [ queryClient, refreshCsrf ] );

	const login = useCallback(
		async ( emailInput, password ) => {
			const {
				role: nextRole,
				player_id: nextPlayerId,
				email: nextEmail,
				csrf_token: csrfToken,
			} = await api.post( 'auth/login', { email: emailInput, password } );
			// Anything cached belongs to whoever was here before — an anonymous
			// visitor, or the previous user on a shared club laptop. Serving it to
			// the account that just signed in would leak their data (['admin-players']
			// carries email addresses) and would show stale, wrongly-scoped views.
			queryClient.clear();
			setCsrfToken( csrfToken );
			setRole( nextRole );
			setPlayerId( nextPlayerId ?? null );
			setEmail( nextEmail ?? emailInput );
			return nextRole;
		},
		[ queryClient ]
	);

	const logout = useCallback( async () => {
		try {
			await api.post( 'auth/logout' );
		} finally {
			// Drop every cached response for the session being ended, so the
			// next user in this tab can't be served the previous one's data.
			queryClient.clear();
			setRole( null );
			setPlayerId( null );
			setEmail( null );
			// The server cleared the CSRF cookie along with the session, so the
			// old token is dead — fetch a fresh (anonymous-scoped) one so a
			// same-page re-login doesn't 403.
			refreshCsrf();
		}
	}, [ refreshCsrf, queryClient ] );

	const value = {
		role,
		playerId,
		email,
		isMember,
		isAdmin,
		login,
		logout,
		refreshCsrf,
	};
	return (
		<AuthContext.Provider value={ value }>
			{ children }
		</AuthContext.Provider>
	);
}

export function useAuth() {
	const ctx = useContext( AuthContext );
	if ( ! ctx ) {
		throw new Error( 'useAuth must be used within <AuthProvider>' );
	}
	return ctx;
}
