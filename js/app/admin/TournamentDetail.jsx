import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { Link } from '../router/router';
import { Notice, ConfirmModal } from '../components/ui';
import { STATUS_LABELS, errorMessage } from './tournamentShared';
import { TournamentBasicTab } from './TournamentBasicTab';
import { TournamentPlayersTab } from './TournamentPlayersTab';
import { TournamentCategoriesTab } from './TournamentCategoriesTab';

// ADMIN. Detail page for a single tournament (= season), reached by clicking a
// name in the Tournaments list (/admin/tournaments/:id). Three tabs: Basic
// details (name/pairing/dates/location), Players (enrolment) and Categories.
// Scoring/tie-breaks/display and delete stay in the separate Settings dialog.

const TABS = [
	{ key: 'basic', label: 'Basic details' },
	{ key: 'players', label: 'Players' },
	{ key: 'categories', label: 'Categories' },
];

export function TournamentDetail( { seasonId } ) {
	const [ tab, setTab ] = useState( 'basic' );
	const [ confirmingStart, setConfirmingStart ] = useState( false );
	const queryClient = useQueryClient();
	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'season', seasonId ],
		queryFn: () => api.get( `seasons/${ seasonId }` ),
	} );

	// Moves the tournament out of preparation into active. Once started it can
	// no longer be deleted, so it's guarded by a confirmation.
	const start = useMutation( {
		mutationFn: () => api.patch( `seasons/${ seasonId }`, { status: 'active' } ),
		onSuccess: () => {
			setConfirmingStart( false );
			queryClient.invalidateQueries( { queryKey: [ 'season', seasonId ] } );
			queryClient.invalidateQueries( { queryKey: [ 'seasons' ] } );
		},
	} );

	if ( isLoading ) {
		return <Notice>Loading…</Notice>;
	}
	if ( isError || ! data?.season ) {
		return (
			<Notice>
				Couldn’t load this tournament.{ ' ' }
				<Link to="/admin/tournaments" className="underline">
					Back to tournaments
				</Link>
				.
			</Notice>
		);
	}

	const { season, players = [] } = data;

	return (
		<div className="flex flex-col gap-6">
			<div>
				<Link
					to="/admin/tournaments"
					className="text-sm text-ink-3 hover:text-ink"
				>
					← Tournaments
				</Link>
				<div className="mt-1 flex items-center justify-between gap-3">
					<div className="flex items-baseline gap-3">
						<h1 className="font-serif text-3xl leading-tight">
							{ season.name }
						</h1>
						<span className="text-xs uppercase tracking-wide text-muted">
							{ STATUS_LABELS[ season.status ] ?? season.status }
						</span>
					</div>
					{ season.status === 'preparation' && (
						<button
							type="button"
							onClick={ () => setConfirmingStart( true ) }
							disabled={ start.isPending }
							className="shrink-0 rounded bg-win px-4 py-2 text-sm font-medium text-paper hover:opacity-90 disabled:opacity-40"
						>
							{ start.isPending ? 'Starting…' : 'Start' }
						</button>
					) }
				</div>
			</div>

			<div className="border-b border-rule">
				<nav className="-mb-px flex gap-6">
					{ TABS.map( ( t ) => (
						<button
							key={ t.key }
							type="button"
							onClick={ () => setTab( t.key ) }
							className={
								'border-b-2 px-1 py-2 text-sm font-medium ' +
								( tab === t.key
									? 'border-accent text-ink'
									: 'border-transparent text-ink-3 hover:text-ink' )
							}
						>
							{ t.label }
						</button>
					) ) }
				</nav>
			</div>

			<div>
				{ tab === 'basic' && (
					<div className="max-w-2xl">
						<TournamentBasicTab season={ season } />
					</div>
				) }
				{ tab === 'players' && (
					<TournamentPlayersTab
						season={ season }
						players={ players }
					/>
				) }
				{ tab === 'categories' && (
					<TournamentCategoriesTab
						season={ season }
						players={ players }
					/>
				) }
			</div>

			{ confirmingStart && (
				<ConfirmModal
					title="Start tournament"
					confirmLabel={ start.isPending ? 'Starting…' : 'Start' }
					onCancel={ () => setConfirmingStart( false ) }
					onConfirm={ () => start.mutate() }
				>
					This moves the tournament from preparation to active. Once
					started it can no longer be deleted.
					{ start.isError && (
						<span className="mt-2 block text-loss">
							{ errorMessage( start.error ) }
						</span>
					) }
				</ConfirmModal>
			) }
		</div>
	);
}
