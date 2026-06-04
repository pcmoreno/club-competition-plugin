import { useEffect } from '@wordpress/element';
import { navigate } from '../router/router';
import { AdminLayout } from './AdminLayout';
import { Tournaments } from './Tournaments';
import { Players } from './Players';
import { Settings } from './Settings';

// Sub-router for the admin sub-app. Owns everything under /admin/* and renders
// the active page inside the AdminLayout sidebar shell. Role gating ('admin')
// happens one level up in App.jsx before this mounts.
export function AdminApp( { path } ) {
	// Bare /admin → default to Tournaments.
	useEffect( () => {
		if ( path === '/admin' || path === '/admin/' ) {
			navigate( '/admin/tournaments' );
		}
	}, [ path ] );

	let page;
	if ( path === '/admin/players' ) {
		page = <Players />;
	} else if ( path === '/admin/settings' ) {
		page = <Settings />;
	} else {
		// /admin and /admin/tournaments
		page = <Tournaments />;
	}

	return <AdminLayout activePath={ path }>{ page }</AdminLayout>;
}
