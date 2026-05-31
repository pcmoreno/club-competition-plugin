import { bootstrap } from '../bootstrap';

/**
 * Thrown for any non-2xx REST response. Carries the HTTP status and the
 * decoded error body so callers can branch on `status` (401/403/404/409/422)
 * or surface `message`.
 */
export class ApiError extends Error {
	constructor( status, body ) {
		super( body?.message || body?.code || `Request failed (${ status })` );
		this.name = 'ApiError';
		this.status = status;
		this.body = body;
	}
}

// The CSRF token (randomized value from login / GET /auth/csrf-token) is held
// in module state and echoed on every write. AuthContext sets it; the api
// client reads it so callers never have to thread it through.
let csrfToken = null;
export function setCsrfToken( token ) {
	csrfToken = token || null;
}

const WRITE_METHODS = new Set( [ 'POST', 'PUT', 'PATCH', 'DELETE' ] );

function buildUrl( path ) {
	// `path` is relative to the REST namespace root, e.g. 'seasons' or
	// 'rounds/12/status'. Leading slashes are tolerated.
	return (
		bootstrap.apiRoot.replace( /\/$/, '' ) +
		'/' +
		String( path ).replace( /^\//, '' )
	);
}

async function request( method, path, { body, signal } = {} ) {
	const headers = { Accept: 'application/json' };
	if ( bootstrap.restNonce ) {
		headers[ 'X-WP-Nonce' ] = bootstrap.restNonce;
	}
	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	// CSRF is required by the $isAdmin permission callback on every write route.
	if ( WRITE_METHODS.has( method ) && csrfToken ) {
		headers[ 'X-SCS-CSRF-Token' ] = csrfToken;
	}

	const res = await fetch( buildUrl( path ), {
		method,
		headers,
		// Send the httpOnly scs_token (JWT) and scs_csrf cookies.
		credentials: 'include',
		body: body !== undefined ? JSON.stringify( body ) : undefined,
		signal,
	} );

	if ( res.status === 204 ) {
		return null;
	}

	const payload = await res.json().catch( () => null );
	if ( ! res.ok ) {
		throw new ApiError( res.status, payload );
	}
	return payload;
}

export const api = {
	get: ( path, opts ) => request( 'GET', path, opts ),
	post: ( path, body, opts ) => request( 'POST', path, { ...opts, body } ),
	put: ( path, body, opts ) => request( 'PUT', path, { ...opts, body } ),
	patch: ( path, body, opts ) => request( 'PATCH', path, { ...opts, body } ),
	del: ( path, opts ) => request( 'DELETE', path, opts ),
};
