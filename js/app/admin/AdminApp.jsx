import { useEffect } from '@wordpress/element';
import { navigate } from '../router/router';
import { AdminLayout } from './AdminLayout';
import { Tournaments } from './Tournaments';
import { Players } from './Players';
import { Settings } from './Settings';

const ROUTES = {
	'/admin/tournaments': Tournaments,
	'/admin/players': Players,
	'/admin/settings': Settings,
};

// Sub-router for the admin sub-app. Owns everything under /admin/* and renders
// the active page inside the AdminLayout sidebar shell. Role gating ('admin')
// happens one level up in App.jsx before this mounts.
export function AdminApp( { path } ) {
	// Normalize bare /admin and any unknown /admin/* to the default page, so the
	// URL and the active nav tab always agree (no orphan page with no tab lit).
	const known = Object.prototype.hasOwnProperty.call( ROUTES, path );
	useEffect( () => {
		if ( ! known ) {
			navigate( '/admin/tournaments' );
		}
	}, [ known ] );

	// Render the default while an unknown path redirects (one frame), so the
	// shell doesn't flash empty before landing on Tournaments.
	const Page = ROUTES[ path ] ?? Tournaments;

	return (
		<AdminLayout activePath={ path }>
			<Page />
		</AdminLayout>
	);
}
