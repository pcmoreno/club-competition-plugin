import {
	createContext,
	useContext,
	useState,
	useCallback,
	useEffect,
} from '@wordpress/element';
import { bootstrap } from '../bootstrap';
import { api, setCsrfToken } from '../api/client';

const AuthContext = createContext( null );

// Holds the session role and the CSRF token used for admin writes.
//
// Role is seeded from the server bootstrap (decoded from the scs_token JWT at
// render), so a logged-in user is recognised on first paint without a
// round-trip. The CSRF token isn't known at render (it would mutate the cookie
// on a GET), so it's fetched lazily once we know the session is authenticated.
export function AuthProvider( { children } ) {
	const [ role, setRole ] = useState( bootstrap.role );
	// The logged-in member's player id (null for anonymous/admins), used to
	// identify "you" in lists.
	const [ playerId, setPlayerId ] = useState( bootstrap.playerId );

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

	// Authenticated on load (returning visitor) → grab a CSRF token so the first
	// write doesn't 403.
	useEffect( () => {
		if ( isMember ) {
			refreshCsrf();
		}
	}, [ isMember, refreshCsrf ] );

	const login = useCallback( async ( email, password ) => {
		const {
			role: nextRole,
			player_id: nextPlayerId,
			csrf_token: csrfToken,
		} = await api.post( 'auth/login', { email, password } );
		setCsrfToken( csrfToken );
		setRole( nextRole );
		setPlayerId( nextPlayerId ?? null );
		return nextRole;
	}, [] );

	const logout = useCallback( async () => {
		try {
			await api.post( 'auth/logout' );
		} finally {
			setCsrfToken( null );
			setRole( null );
			setPlayerId( null );
		}
	}, [] );

	const value = {
		role,
		playerId,
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
