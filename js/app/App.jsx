import { useState, useEffect } from '@wordpress/element';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from './auth/AuthContext';
import { useRoute, navigate, matchPath } from './router/router';
import { TopBar } from './layout/TopBar';
import { SubNav } from './layout/SubNav';
import { Placeholder } from './layout/Page';

import { Pairings } from './routes/Pairings';
import { Standings } from './routes/Standings';
import { RoundHistory } from './routes/RoundHistory';
import { Players } from './routes/Players';
import { PlayerDetail } from './routes/PlayerDetail';
import { Admin } from './routes/Admin';
import {
	Login,
	ForgotPassword,
	ResetPassword,
	AcceptInvite,
} from './auth/AuthScreens';

const queryClient = new QueryClient( {
	defaultOptions: {
		queries: { staleTime: 30_000, refetchOnWindowFocus: false },
	},
} );

// Auth screens render bare (top bar, no page tabs). Everything else renders
// inside the full shell. `need` gates a route by role.
const AUTH_ROUTES = {
	'/login': Login,
	'/forgot-password': ForgotPassword,
	'/reset-password': ResetPassword,
	'/accept-invite': AcceptInvite,
};

function resolveView( path, ctx ) {
	const playerMatch = matchPath( '/players/:id', path );
	if ( playerMatch ) {
		return {
			need: 'member',
			node: <PlayerDetail playerId={ playerMatch.id } />,
		};
	}

	switch ( path ) {
		case '/pairings':
			return {
				need: 'public',
				node: <Pairings seasonId={ ctx.seasonId } />,
			};
		case '/standings':
			return {
				need: 'public',
				node: <Standings seasonId={ ctx.seasonId } />,
			};
		case '/rounds':
			return {
				need: 'member',
				node: <RoundHistory seasonId={ ctx.seasonId } />,
			};
		case '/players':
			return {
				need: 'member',
				node: <Players seasonId={ ctx.seasonId } />,
			};
		case '/admin':
			return { need: 'admin', node: <Admin /> };
		default:
			return null;
	}
}

function Shell() {
	const path = useRoute();
	const { isMember, isAdmin } = useAuth();
	const [ seasonId, setSeasonId ] = useState( null );

	// Land "/" on the default public view.
	useEffect( () => {
		if ( path === '/' ) {
			navigate( '/pairings' );
		}
	}, [ path ] );

	// Auth screens: bare chrome.
	const AuthView = AUTH_ROUTES[ path ];
	if ( AuthView ) {
		return (
			<div className="min-h-screen">
				<TopBar seasonId={ seasonId } onSeasonChange={ setSeasonId } />
				<AuthView />
			</div>
		);
	}

	const view = resolveView( path, { seasonId } );

	let body;
	if ( ! view ) {
		body = (
			<Placeholder title="Not found">
				That page does not exist.{ ' ' }
				<a href="#/pairings">Go to pairings.</a>
			</Placeholder>
		);
	} else if (
		( view.need === 'member' && ! isMember ) ||
		( view.need === 'admin' && ! isAdmin )
	) {
		// Gated route, insufficient role → send to sign in.
		body = (
			<Placeholder title="Members only">
				Please <a href="#/login">sign in</a> to view this page.
			</Placeholder>
		);
	} else {
		body = view.node;
	}

	return (
		<div className="min-h-screen">
			<TopBar seasonId={ seasonId } onSeasonChange={ setSeasonId } />
			<SubNav activePath={ path } />
			{ body }
		</div>
	);
}

export function App() {
	return (
		<QueryClientProvider client={ queryClient }>
			<AuthProvider>
				<Shell />
			</AuthProvider>
		</QueryClientProvider>
	);
}
