import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { Link } from '../router/router';
import { Notice, ConfirmModal } from '../components/ui';
import {
	STATUS_LABELS,
	errorMessage,
	isLocked,
	isTeamLocked,
} from './tournamentShared';
import { TournamentBasicTab } from './TournamentBasicTab';
import { TournamentPairingsTab } from './TournamentPairingsTab';
import { TournamentPlayersTab } from './TournamentPlayersTab';
import { TournamentAbsencesTab } from './TournamentAbsencesTab';
import { TournamentCategoriesTab } from './TournamentCategoriesTab';
import { TournamentSettingsTab } from './TournamentSettingsTab';
import { keys } from '../api/keys';

// ADMIN. Detail page for a single tournament (= season), reached by clicking a
// name in the Tournaments list (/admin/tournaments/:id). Everything about one
// tournament lives here: Basic details (name/pairing/dates/location), Pairings,
// Players (enrolment), Categories, and Settings (the engine's pairing/scoring/
// display knobs, plus delete).

// The Pairings tab only applies once the tournament has started (you enrol and
// set categories in preparation, then Start). It's the default tab while active.
//
// Absences is offered wherever rounds are created one at a time — a full
// schedule pairs every round up front, so a standing absence has nothing to
// apply to.
//
// Teams and Categories are the same tab: a team tournament groups its players
// into teams instead of categories, out of the same column.
function tabsFor( season ) {
	return [
		{ key: 'basic', label: 'Basic details' },
		...( season.status !== 'preparation'
			? [ { key: 'pairings', label: 'Pairings' } ]
			: [] ),
		{ key: 'players', label: 'Players' },
		...( season.cadence !== 'full'
			? [ { key: 'absences', label: 'Absences' } ]
			: [] ),
		{ key: 'categories', label: season.is_team ? 'Teams' : 'Categories' },
		{ key: 'settings', label: 'Settings' },
	];
}

export function TournamentDetail( { seasonId } ) {
	const [ tab, setTab ] = useState( null );
	const [ confirmingStart, setConfirmingStart ] = useState( false );
	const queryClient = useQueryClient();
	const { data, isLoading, isError } = useQuery( {
		queryKey: keys.season( seasonId ),
		queryFn: () => api.get( `seasons/${ seasonId }` ),
	} );

	// Moves the tournament out of preparation into active. Once started it can
	// no longer be deleted, so it's guarded by a confirmation.
	const start = useMutation( {
		mutationFn: () => api.patch( `seasons/${ seasonId }`, { status: 'active' } ),
		onSuccess: () => {
			setConfirmingStart( false );
			queryClient.invalidateQueries( { queryKey: keys.season( seasonId ) } );
			queryClient.invalidateQueries( { queryKey: keys.seasons() } );
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
	const tabs = tabsFor( season );
	const activeTab = tab ?? ( season.status === 'active' ? 'pairings' : 'basic' );
	const locked = isLocked( season );

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

			{ locked && (
				<p className="rounded border border-rule bg-surface px-3 py-2 text-sm text-ink-3">
					This tournament is completed, so its record is read-only. The
					standings columns on the Settings tab can still be changed.
				</p>
			) }

			<div className="border-b border-rule">
				<nav className="-mb-px flex gap-6">
					{ tabs.map( ( t ) => (
						<button
							key={ t.key }
							type="button"
							onClick={ () => setTab( t.key ) }
							className={
								'border-b-2 px-1 py-2 text-sm font-medium ' +
								( activeTab === t.key
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
				{ activeTab === 'basic' && (
					<div className="max-w-2xl">
						<TournamentBasicTab
							season={ season }
							locked={ locked }
						/>
					</div>
				) }
				{ activeTab === 'pairings' && (
					<TournamentPairingsTab
						season={ season }
						players={ players }
						locked={ locked }
					/>
				) }
				{ activeTab === 'players' && (
					<TournamentPlayersTab
						season={ season }
						players={ players }
						locked={ locked }
					/>
				) }
				{ activeTab === 'absences' && (
					<TournamentAbsencesTab
						season={ season }
						locked={ locked }
					/>
				) }
				{ activeTab === 'categories' && (
					<TournamentCategoriesTab
						season={ season }
						players={ players }
						locked={ locked || isTeamLocked( season ) }
					/>
				) }
				{ activeTab === 'settings' && (
					<TournamentSettingsTab
						season={ season }
						locked={ locked }
					/>
				) }
			</div>

			{ confirmingStart && (
				<ConfirmModal
					title="Start tournament"
					confirmLabel={ start.isPending ? 'Starting…' : 'Start' }
					busy={ start.isPending }
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
