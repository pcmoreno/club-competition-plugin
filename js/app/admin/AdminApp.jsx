import { useEffect } from '@wordpress/element';
import { navigate, matchPath } from '../router/router';
import { AdminLayout } from './AdminLayout';
import { Tournaments } from './Tournaments';
import { TournamentDetail } from './TournamentDetail';
import { Players } from './Players';
import { Admins } from './Admins';
import { Settings } from './Settings';

const ROUTES = {
	'/admin/tournaments': Tournaments,
	'/admin/players': Players,
	'/admin/admins': Admins,
	'/admin/settings': Settings,
};

// Sub-router for the admin sub-app. Owns everything under /admin/* and renders
// the active page inside the AdminLayout sidebar shell. Role gating ('admin')
// happens one level up in App.jsx before this mounts.
export function AdminApp( { path } ) {
	// The tournament detail page is parameterised (/admin/tournaments/:id); the
	// rest are exact matches. The detail page still lights the Tournaments tab.
	const detail = matchPath( '/admin/tournaments/:id', path );
	const known =
		detail !== null ||
		Object.prototype.hasOwnProperty.call( ROUTES, path );

	// Normalize bare /admin and any unknown /admin/* to the default page, so the
	// URL and the active nav tab always agree (no orphan page with no tab lit).
	useEffect( () => {
		if ( ! known ) {
			navigate( '/admin/tournaments' );
		}
	}, [ known ] );

	const activePath = detail ? '/admin/tournaments' : path;

	let page;
	if ( detail ) {
		page = <TournamentDetail seasonId={ detail.id } />;
	} else {
		// Render the default while an unknown path redirects (one frame), so the
		// shell doesn't flash empty before landing on Tournaments.
		const Page = ROUTES[ path ] ?? Tournaments;
		page = <Page />;
	}

	return <AdminLayout activePath={ activePath }>{ page }</AdminLayout>;
}
