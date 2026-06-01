import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { Notice, formatDate } from '../components/ui';
import { Square, resultToken, categoryLabel } from '../components/game';

// PUBLIC. Shows the most-recent non-draft round of the selected tournament:
// pairings appear at `published`, results at `complete`. Backed by
// GET /seasons/{id}/rounds (to pick the round) + GET /rounds/{id} (enriched
// games + byes). The member "I won't be present" action and PDF export are
// deferred (they need backend endpoints that don't exist yet).

const STATUS_LABEL = {
	published: 'Pairings published',
	finalised: 'Pairings final',
	complete: 'Results in',
};

// Most-recent round that has left draft (highest round number).
function pickCurrentRound( rounds ) {
	const playable = rounds.filter( ( r ) => r.status !== 'draft' );
	if ( playable.length === 0 ) {
		return null;
	}
	return playable.reduce( ( a, b ) =>
		b.round_number > a.round_number ? b : a
	);
}

function PlayerCell( { player, color } ) {
	return (
		<span className="inline-flex items-center gap-2">
			<Square color={ color } />
			<span>
				{ player?.name ?? '—' }
				{ player?.elo ? (
					<span className="num ml-1 text-ink-3">
						({ player.elo })
					</span>
				) : null }
			</span>
		</span>
	);
}

export function Pairings( { seasonId } ) {
	const roundsQuery = useQuery( {
		queryKey: [ 'rounds', seasonId ],
		queryFn: () => api.get( `seasons/${ seasonId }/rounds` ),
		enabled: seasonId !== null,
	} );

	const current = roundsQuery.data
		? pickCurrentRound( roundsQuery.data )
		: null;

	const roundQuery = useQuery( {
		queryKey: [ 'round', current?.id ],
		queryFn: () => api.get( `rounds/${ current.id }` ),
		enabled: current !== null,
	} );

	let content;
	if ( seasonId === null || roundsQuery.isLoading ) {
		content = <Notice>Loading…</Notice>;
	} else if ( roundsQuery.isError ) {
		content = <Notice>Couldn’t load rounds. Please try again.</Notice>;
	} else if ( current === null ) {
		content = <Notice>No pairings have been published yet.</Notice>;
	} else if ( roundQuery.isLoading ) {
		content = <Notice>Loading round…</Notice>;
	} else if ( roundQuery.isError || ! roundQuery.data ) {
		content = <Notice>Couldn’t load this round. Please try again.</Notice>;
	} else {
		content = <RoundTable round={ current } data={ roundQuery.data } />;
	}

	return (
		<Page>
			<div className="mb-6 flex items-baseline justify-between gap-4">
				<h1 className="font-serif text-[38px] leading-[1.1]">
					Pairings
				</h1>
				{ current && (
					<button
						type="button"
						onClick={ () => window.print() }
						className="text-sm text-ink-3 hover:text-ink"
					>
						Print
					</button>
				) }
			</div>
			{ content }
		</Page>
	);
}

function RoundTable( { round, data } ) {
	const { games = [], byes = [] } = data;
	const dateLabel = formatDate( round.date );

	return (
		<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
			<div className="flex items-center justify-between gap-3 border-b border-rule px-5 py-3.5">
				<h2 className="font-serif text-xl">
					Round { round.round_number }
				</h2>
				<div className="flex items-center gap-3 text-sm text-ink-3">
					{ dateLabel && <span>{ dateLabel }</span> }
					<span className="rounded-full bg-surface-2 px-2.5 py-0.5 text-xs font-medium text-ink-2">
						{ STATUS_LABEL[ round.status ] ?? round.status }
					</span>
				</div>
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
						<th className="w-24 px-4 py-2 font-medium">Cat</th>
					</tr>
				</thead>
				<tbody>
					{ games.map( ( g ) => (
						<tr key={ g.id } className="border-b border-rule-soft">
							<td className="num px-4 py-2.5 text-ink-3">
								{ g.board ?? '' }
							</td>
							<td className="px-4 py-2.5">
								<PlayerCell player={ g.white } color="white" />
							</td>
							<td className="num px-4 py-2.5 text-center font-mono text-ink">
								{ resultToken( g.result ) }
							</td>
							<td className="px-4 py-2.5">
								<PlayerCell player={ g.black } color="black" />
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ categoryLabel( g.white, g.black ) }
							</td>
						</tr>
					) ) }
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
