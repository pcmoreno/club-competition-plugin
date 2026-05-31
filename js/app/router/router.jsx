import { useState, useEffect, useCallback } from '@wordpress/element';

/**
 * Minimal dependency-free hash router. The app is mounted inside a WordPress
 * page via the [clubcompetitie] shortcode, so hash routing (#/standings) keeps
 * all view state client-side with no server rewrite rules. Swap for
 * react-router later if nested layouts/loaders are needed — views only depend
 * on the `useRoute`/`navigate`/`Link` surface below.
 */

function parseHash() {
	const raw = window.location.hash.replace( /^#/, '' ) || '/';
	const path = raw.split( '?' )[ 0 ] || '/';
	return path.startsWith( '/' ) ? path : '/' + path;
}

export function navigate( path ) {
	const next = path.startsWith( '/' ) ? path : '/' + path;
	if ( parseHash() === next ) {
		return;
	}
	window.location.hash = next;
}

// Reads a query param from the hash (`#/reset?token=abc`) or, failing that,
// the page URL (`?token=abc`). Invite/reset links land with the token in one
// or the other depending on how the email link is built.
export function getQueryParam( name ) {
	const hashQuery = window.location.hash.split( '?' )[ 1 ] || '';
	const fromHash = new URLSearchParams( hashQuery ).get( name );
	if ( fromHash ) {
		return fromHash;
	}
	return new URLSearchParams( window.location.search ).get( name );
}

export function useRoute() {
	const [ path, setPath ] = useState( parseHash );

	useEffect( () => {
		const onChange = () => setPath( parseHash() );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );

	return path;
}

// Matches a route pattern with `:param` segments against a path.
// Returns the params object on a match, or null. Example:
//   matchPath('/players/:id', '/players/42') → { id: '42' }
export function matchPath( pattern, path ) {
	const pSeg = pattern.split( '/' ).filter( Boolean );
	const aSeg = path.split( '/' ).filter( Boolean );
	if ( pSeg.length !== aSeg.length ) {
		return null;
	}
	const params = {};
	for ( let i = 0; i < pSeg.length; i++ ) {
		if ( pSeg[ i ].startsWith( ':' ) ) {
			params[ pSeg[ i ].slice( 1 ) ] = decodeURIComponent( aSeg[ i ] );
		} else if ( pSeg[ i ] !== aSeg[ i ] ) {
			return null;
		}
	}
	return params;
}

export function Link( { to, className, children, ...rest } ) {
	const onClick = useCallback(
		( e ) => {
			e.preventDefault();
			navigate( to );
		},
		[ to ]
	);
	return (
		<a
			href={ '#' + to }
			className={ className }
			onClick={ onClick }
			{ ...rest }
		>
			{ children }
		</a>
	);
}
