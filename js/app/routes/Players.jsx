import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { Link } from '../router/router';
import { useAuth } from '../auth/AuthContext';
import { Notice, YouTag, youRowClass } from '../components/ui';

// MEMBER. Plain roster (NOT standings) scoped to the selected tournament:
// Name · Cat · Elo, sorted by Elo desc; rows link to player detail. Backed by
// the enriched GET /seasons/{id} players list. Tournament selection is the
// global switcher, so there's no per-view search/filter.

export function Players( { seasonId } ) {
	const { playerId } = useAuth();
	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'season', seasonId ],
		queryFn: () => api.get( `seasons/${ seasonId }` ),
		enabled: seasonId !== null,
	} );

	let content;
	if ( seasonId === null || isLoading ) {
		content = <Notice>Loading…</Notice>;
	} else if ( isError || ! data ) {
		content = <Notice>Couldn’t load players. Please try again.</Notice>;
	} else if ( ! data.players || data.players.length === 0 ) {
		content = <Notice>No players are enrolled yet.</Notice>;
	} else {
		// Highest-rated first; unrated (elo 0/null) sort to the bottom.
		const players = [ ...data.players ].sort(
			( a, b ) => ( b.elo || 0 ) - ( a.elo || 0 )
		);
		content = (
			<RosterTable
				players={ players }
				meId={ playerId }
				seasonId={ seasonId }
			/>
		);
	}

	return (
		<Page>
			<h1 className="mb-6 font-serif text-[38px] leading-[1.1]">
				Players
			</h1>
			{ content }
		</Page>
	);
}

function RosterTable( { players, meId, seasonId } ) {
	return (
		<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
			<table className="w-full text-sm">
				<thead>
					<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
						<th className="px-4 py-2 font-medium">Name</th>
						<th className="w-24 px-4 py-2 font-medium">Cat</th>
						<th className="w-24 px-4 py-2 text-right font-medium">
							Elo
						</th>
					</tr>
				</thead>
				<tbody>
					{ players.map( ( p ) => {
						const isMe = meId !== null && p.player_id === meId;
						return (
							<tr
								key={ p.season_player_id }
								className={ [
									'border-b border-rule-soft',
									isMe ? youRowClass : '',
								].join( ' ' ) }
							>
								<td className="px-4 py-2.5">
									<Link
										to={ `/seasons/${ seasonId }/players/${ p.player_id }` }
										className="text-ink no-underline hover:text-accent"
									>
										{ p.name ?? '—' }
									</Link>
									{ isMe && <YouTag /> }
								</td>
								<td className="px-4 py-2.5 text-ink-3">
									{ p.category ?? '—' }
								</td>
								<td className="num px-4 py-2.5 text-right font-mono">
									{ p.elo ? p.elo : '—' }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
