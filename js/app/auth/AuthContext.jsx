import {
	createContext,
	useContext,
	useState,
	useCallback,
	useEffect,
} from '@wordpress/element';
import { useQueryClient } from '@tanstack/react-query';
import { bootstrap } from '../bootstrap';
import { api, setCsrfToken, setUnauthenticatedHandler } from '../api/client';
import { navigate } from '../router/router';

const AuthContext = createContext( null );

// Holds the session role and the CSRF token used for writes (admin writes,
// plus login/logout, which need it even when signed out).
//
// Role is seeded from the server bootstrap (decoded from the scs_token JWT at
// render), so a logged-in user is recognised on first paint without a
// round-trip. The CSRF token isn't known at render (it would mutate the
// cookie on a GET), so it's fetched lazily on mount instead.
export function AuthProvider( { children } ) {
	// AuthProvider sits inside QueryClientProvider (see App.jsx), so the cache
	// can be dropped whenever the identity behind it changes.
	const queryClient = useQueryClient();
	const [ role, setRole ] = useState( bootstrap.role );
	// The logged-in member's player id (null for anonymous/admins), used to
	// identify "you" in lists.
	const [ playerId, setPlayerId ] = useState( bootstrap.playerId );
	// The signed-in user's own email, shown in the top-bar account menu.
	const [ email, setEmail ] = useState( bootstrap.email );

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

	// The JWT can expire mid-session. Role is seeded from the server bootstrap
	// and never revisited, so without this the chrome keeps offering a session
	// that no longer exists while every query fails on its own.
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

	const login = useCallback( async ( emailInput, password ) => {
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
	}, [ queryClient ] );

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
