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

// Called when the server says we're not signed in, so the UI can stop showing a
// session that no longer exists. AuthProvider registers it; keeping it a
// callback avoids importing AuthContext here and creating a cycle.
let onUnauthenticated = null;
export function setUnauthenticatedHandler( fn ) {
	onUnauthenticated = fn;
}

const WRITE_METHODS = new Set( [ 'POST', 'PUT', 'PATCH', 'DELETE' ] );

// Shared promise so several writes firing at once fetch one token, not one each.
let csrfFetch = null;
function fetchCsrfToken() {
	csrfFetch ??= request( 'GET', 'auth/csrf-token' )
		.then( ( res ) => {
			csrfToken = res?.csrf_token ?? null;
			return csrfToken;
		} )
		.catch( () => null )
		.finally( () => {
			csrfFetch = null;
		} );
	return csrfFetch;
}

function buildUrl( path, params ) {
	// `path` is relative to the REST namespace root, e.g. 'seasons' or
	// 'rounds/12/status'. Leading slashes are tolerated.
	let url =
		bootstrap.apiRoot.replace( /\/$/, '' ) +
		'/' +
		String( path ).replace( /^\//, '' );
	// Append query params with the right separator: under plain permalinks
	// apiRoot is already a query string ('?rest_route=…'), so a further param
	// must use '&', not a second '?'.
	if ( params ) {
		const qs = new URLSearchParams( params ).toString();
		if ( qs ) {
			url += ( url.includes( '?' ) ? '&' : '?' ) + qs;
		}
	}
	return url;
}

async function request(
	method,
	path,
	{ body, signal, params } = {},
	retry = true
) {
	const isWrite = WRITE_METHODS.has( method );

	// Login and logout need the header too, so an anonymous visitor who submits
	// before AuthProvider's token has landed would otherwise 403 on correct
	// credentials. Fetch on demand rather than gating the form.
	if ( isWrite && ! csrfToken ) {
		await fetchCsrfToken();
	}

	const headers = { Accept: 'application/json' };
	// No X-WP-Nonce: the plugin authenticates with the scs_token JWT plus the
	// scs_csrf double-submit token, and no scs/v1 route verifies a wp_rest
	// nonce. Sending one is pure liability — core rejects a *present but
	// expired* nonce with a 403 before any permission_callback runs, so a page
	// served from cache older than the nonce tick would 403 every call,
	// public reads included.
	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	// CSRF is required by the $isAdmin permission callback on every write route.
	if ( isWrite && csrfToken ) {
		headers[ 'X-SCS-CSRF-Token' ] = csrfToken;
	}

	const res = await fetch( buildUrl( path, params ), {
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
		// The CSRF cookie outlives no session but does expire; without this a
		// stale token bricks every write in the tab with no way to recover.
		if (
			res.status === 403 &&
			isWrite &&
			retry &&
			payload?.code === 'invalid_csrf_token'
		) {
			csrfToken = null;
			await fetchCsrfToken();
			return request( method, path, { body, signal, params }, false );
		}
		// Not on login: a wrong password is also a 401 and must not be treated
		// as an expired session.
		if ( res.status === 401 && path !== 'auth/login' ) {
			onUnauthenticated?.();
		}
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
