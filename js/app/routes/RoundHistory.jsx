import { useState, useEffect } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { useAuth } from '../auth/AuthContext';
import { Notice, YouTag, youRowClass, formatDate } from '../components/ui';
import { Square, resultToken } from '../components/game';

// MEMBER. Browse any played round: a round navigator + the round's games.
// Backed by GET /seasons/{id}/rounds (navigator) + GET /rounds/{id} (games +
// byes). The "Standings after Rd N" snapshot and Movers are blocked on
// ScoringService / per-round ranking snapshots, so the right column is a
// placeholder for now.

// Name with category + elo inline, e.g. "Peter de Roode (A · 2121)".
function PlayerInline( { player, color } ) {
	const cat = player?.category;
	const elo = player?.elo;
	const meta = [ cat, elo ? String( elo ) : null ]
		.filter( Boolean )
		.join( ' · ' );
	return (
		<span className="inline-flex items-center gap-2">
			<Square color={ color } />
			<span>
				{ player?.name ?? '—' }
				{ meta && (
					<span className="num ml-1 text-ink-3">({ meta })</span>
				) }
			</span>
		</span>
	);
}

export function RoundHistory( { seasonId } ) {
	const { playerId } = useAuth();
	const roundsQuery = useQuery( {
		queryKey: [ 'rounds', seasonId ],
		queryFn: () => api.get( `seasons/${ seasonId }/rounds` ),
		enabled: seasonId !== null,
	} );

	const rounds = ( roundsQuery.data ?? [] )
		.filter( ( r ) => r.status !== 'draft' )
		.sort( ( a, b ) => a.round_number - b.round_number );

	const [ selectedId, setSelectedId ] = useState( null );

	// Default to the latest played round; re-default if the selection isn't in
	// the current list (e.g. after switching tournaments).
	useEffect( () => {
		if ( rounds.length === 0 ) {
			return;
		}
		if ( ! rounds.some( ( r ) => r.id === selectedId ) ) {
			setSelectedId( rounds[ rounds.length - 1 ].id );
		}
	}, [ rounds, selectedId ] );

	const roundQuery = useQuery( {
		queryKey: [ 'round', selectedId ],
		queryFn: () => api.get( `rounds/${ selectedId }` ),
		enabled: selectedId !== null,
	} );

	if ( seasonId === null || roundsQuery.isLoading ) {
		return <Shell>{ <Notice>Loading…</Notice> }</Shell>;
	}
	if ( roundsQuery.isError ) {
		return (
			<Shell>
				<Notice>Couldn’t load rounds. Please try again.</Notice>
			</Shell>
		);
	}
	if ( rounds.length === 0 ) {
		return (
			<Shell>
				<Notice>No rounds have been played yet.</Notice>
			</Shell>
		);
	}

	const selected = rounds.find( ( r ) => r.id === selectedId ) ?? null;
	const index = rounds.findIndex( ( r ) => r.id === selectedId );

	return (
		<Shell
			nav={
				<RoundNavigator
					rounds={ rounds }
					selectedId={ selectedId }
					onSelect={ setSelectedId }
					onPrev={ () =>
						index > 0 && setSelectedId( rounds[ index - 1 ].id )
					}
					onNext={ () =>
						index < rounds.length - 1 &&
						setSelectedId( rounds[ index + 1 ].id )
					}
					canPrev={ index > 0 }
					canNext={ index < rounds.length - 1 }
				/>
			}
		>
			<div className="grid gap-6 lg:grid-cols-3">
				<div className="lg:col-span-2">
					{ roundQuery.isLoading && <Notice>Loading round…</Notice> }
					{ ( roundQuery.isError || ! roundQuery.data ) &&
						! roundQuery.isLoading && (
							<Notice>Couldn’t load this round.</Notice>
						) }
					{ roundQuery.data && (
						<GamesCard
							round={ selected }
							data={ roundQuery.data }
							meId={ playerId }
						/>
					) }
				</div>
				<div>
					<StandingsAfterPlaceholder round={ selected } />
				</div>
			</div>
		</Shell>
	);
}

function Shell( { children, nav } ) {
	return (
		<Page>
			<div className="mb-6 flex items-baseline justify-between gap-4">
				<h1 className="font-serif text-[38px] leading-[1.1]">
					Round history
				</h1>
				<button
					type="button"
					onClick={ () => window.print() }
					className="text-sm text-ink-3 hover:text-ink"
				>
					Print
				</button>
			</div>
			{ nav }
			<div className="mt-6">{ children }</div>
		</Page>
	);
}

function RoundNavigator( {
	rounds,
	selectedId,
	onSelect,
	onPrev,
	onNext,
	canPrev,
	canNext,
} ) {
	const btn =
		'rounded border border-rule px-2.5 py-1 text-sm disabled:opacity-40';
	return (
		<div className="flex items-center gap-2">
			<button
				type="button"
				className={ btn }
				onClick={ onPrev }
				disabled={ ! canPrev }
			>
				← Prev
			</button>
			<div className="flex flex-1 flex-wrap gap-1">
				{ rounds.map( ( r ) => (
					<button
						key={ r.id }
						type="button"
						onClick={ () => onSelect( r.id ) }
						className={ [
							'num h-7 w-7 rounded text-sm',
							r.id === selectedId
								? 'bg-ink text-paper'
								: 'text-ink-3 hover:bg-surface-2',
						].join( ' ' ) }
					>
						{ r.round_number }
					</button>
				) ) }
			</div>
			<button
				type="button"
				className={ btn }
				onClick={ onNext }
				disabled={ ! canNext }
			>
				Next →
			</button>
		</div>
	);
}

function GamesCard( { round, data, meId } ) {
	const { games = [], byes = [] } = data;
	const dateLabel = formatDate( round?.date );

	return (
		<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
			<div className="flex items-center justify-between gap-3 border-b border-rule px-5 py-3.5">
				<h2 className="font-serif text-xl">
					Round { round?.round_number } games
				</h2>
				{ dateLabel && (
					<span className="text-sm text-ink-3">{ dateLabel }</span>
				) }
			</div>

			<table className="w-full text-sm">
				<thead>
					<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
						<th className="w-10 px-4 py-2 font-medium">Bd</th>
						<th className="px-4 py-2 font-medium">White</th>
						<th className="w-20 px-4 py-2 text-center font-medium">
							Result
						</th>
						<th className="px-4 py-2 font-medium">Black</th>
					</tr>
				</thead>
				<tbody>
					{ games.map( ( g ) => {
						const whiteIsMe =
							meId !== null && g.white?.player_id === meId;
						const blackIsMe =
							meId !== null && g.black?.player_id === meId;
						return (
							<tr
								key={ g.id }
								className={ [
									'border-b border-rule-soft',
									whiteIsMe || blackIsMe ? youRowClass : '',
								].join( ' ' ) }
							>
								<td className="num px-4 py-2.5 text-ink-3">
									{ g.board ?? '' }
								</td>
								<td className="px-4 py-2.5">
									<PlayerInline
										player={ g.white }
										color="white"
									/>
									{ whiteIsMe && <YouTag /> }
								</td>
								<td className="num px-4 py-2.5 text-center font-mono text-ink">
									{ resultToken( g.result ) }
								</td>
								<td className="px-4 py-2.5">
									<PlayerInline
										player={ g.black }
										color="black"
									/>
									{ blackIsMe && <YouTag /> }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			{ byes.length > 0 && (
				<div className="border-t border-rule px-5 py-3 text-sm text-ink-3">
					<span className="font-medium text-ink-2">Bye:</span>{ ' ' }
					{ byes.map( ( b ) => b.name ?? '—' ).join( ', ' ) }
				</div>
			) }
		</div>
	);
}

function StandingsAfterPlaceholder( { round } ) {
	return (
		<div className="rounded border border-rule bg-surface shadow-sm">
			<div className="border-b border-rule px-5 py-3.5">
				<h2 className="font-serif text-xl">
					Standings after Round { round?.round_number }
				</h2>
			</div>
			<div className="p-5 text-sm text-ink-3">
				The per-round standings snapshot and movers (▲/▼) arrive with
				the scoring engine (Keizer). Not available yet.
			</div>
		</div>
	);
}
