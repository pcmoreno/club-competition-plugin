import { useState, useEffect } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { Link } from '../router/router';
import { useAuth } from '../auth/AuthContext';
import { Notice, YouTag, youRowClass } from '../components/ui';
import { pieceForRank } from '../components/game';

// PUBLIC. Pieces-ladder standings for the selected tournament, read from the
// latest frozen StandingsSnapshot (no live compute). The piece reflects the
// engine's default ranking (Keizer score) and stays attached to the player
// even when the table is re-sorted by another column — sort is a temporary
// view, the piece always shows true standing. Δrank/Movers need a prior
// snapshot and are omitted until the scoring engine produces per-round ones.

// Sortable numeric columns (name is intentionally not sortable). The score
// column is labelled engine-neutrally ("Score", not "Keizer") since other
// pairing systems rank by their own metric.
const COL = {
	rank: { key: 'rank', label: 'Rank', dir: 'asc' },
	games: { key: 'games', label: 'Games', dir: 'desc' },
	score: { key: 'keizer_score', label: 'Score', dir: 'desc' },
	points: { key: 'classical_points', label: 'Pts', dir: 'desc' },
	wins: { key: 'wins', label: 'W', dir: 'desc' },
	draws: { key: 'draws', label: 'D', dir: 'desc' },
	losses: { key: 'losses', label: 'L', dir: 'desc' },
	byes: { key: 'byes', label: 'Byes', dir: 'desc' },
	colorBalance: { key: 'color_balance', label: 'Color', dir: 'desc' },
	tpr: { key: 'tpr', label: 'TPR', dir: 'desc' },
};

export function Standings( { seasonId } ) {
	const { playerId } = useAuth();
	const [ sort, setSort ] = useState( { key: 'rank', dir: 'asc' } );
	const [ category, setCategory ] = useState( 'Overall' );

	// A sort/category is a per-season view; reset to the default ranking when
	// the selected tournament changes so it doesn't carry over to another season.
	useEffect( () => {
		setSort( { key: 'rank', dir: 'asc' } );
		setCategory( 'Overall' );
	}, [ seasonId ] );

	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'standings', seasonId ],
		queryFn: () => api.get( `seasons/${ seasonId }/standings` ),
		enabled: seasonId !== null,
	} );

	if ( seasonId === null || isLoading ) {
		return (
			<Wrap>
				<Notice>Loading…</Notice>
			</Wrap>
		);
	}
	if ( isError || ! data ) {
		return (
			<Wrap>
				<Notice>Couldn’t load standings. Please try again.</Notice>
			</Wrap>
		);
	}
	const all = data.standings ?? [];
	if ( all.length === 0 ) {
		return (
			<Wrap>
				<Notice>
					No standings yet — they appear once the first round is
					complete.
				</Notice>
			</Wrap>
		);
	}

	const total = all.length;
	const categories = [
		'Overall',
		...[
			...new Set( all.map( ( r ) => r.category ).filter( Boolean ) ),
		].sort(),
	];

	const rows = all
		.filter( ( r ) => category === 'Overall' || r.category === category )
		.sort( ( a, b ) => {
			const av = a[ sort.key ] ?? 0;
			const bv = b[ sort.key ] ?? 0;
			return sort.dir === 'asc' ? av - bv : bv - av;
		} );

	const onSort = ( col ) =>
		setSort( ( s ) =>
			s.key === col.key
				? { key: col.key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
				: { key: col.key, dir: col.dir }
		);

	// Hide columns that carry no data for this season: Score (point-ranked
	// seasons have no Keizer score), Category (an undivided single-pool season),
	// and Byes (a season that recorded none).
	const hasScore = rows.some( ( r ) => r.keizer_score != null );
	const hasCat = rows.some( ( r ) => r.category != null && r.category !== '' );
	const hasByes = rows.some( ( r ) => ( r.byes ?? 0 ) > 0 );

	return (
		<Wrap
			rounds={ data.completed_rounds }
			categories={ categories }
			category={ category }
			onCategory={ setCategory }
		>
			<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
				<table className="w-full text-sm">
					<thead>
						<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
							<SortTh
								col={ COL.rank }
								sort={ sort }
								onSort={ onSort }
								className="w-16"
							/>
							<th className="px-4 py-2 font-medium">Player</th>
							{ hasCat && (
								<th className="w-16 px-4 py-2 font-medium">
									Cat
								</th>
							) }
							<SortTh
								col={ COL.games }
								sort={ sort }
								onSort={ onSort }
								className="w-16"
							/>
							{ hasScore && (
								<SortTh
									col={ COL.score }
									sort={ sort }
									onSort={ onSort }
									className="w-20"
								/>
							) }
							<SortTh
								col={ COL.points }
								sort={ sort }
								onSort={ onSort }
								className="w-16"
							/>
							<SortTh
								col={ COL.wins }
								sort={ sort }
								onSort={ onSort }
								className="w-12"
							/>
							<SortTh
								col={ COL.draws }
								sort={ sort }
								onSort={ onSort }
								className="w-12"
							/>
							<SortTh
								col={ COL.losses }
								sort={ sort }
								onSort={ onSort }
								className="w-12"
							/>
							{ hasByes && (
								<SortTh
									col={ COL.byes }
									sort={ sort }
									onSort={ onSort }
									className="w-16"
								/>
							) }
							<SortTh
								col={ COL.colorBalance }
								sort={ sort }
								onSort={ onSort }
								className="w-16"
							/>
							<SortTh
								col={ COL.tpr }
								sort={ sort }
								onSort={ onSort }
								className="w-16"
							/>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( r ) => {
							const isMe =
								playerId !== null && r.player_id === playerId;
							return (
								<tr
									key={ r.season_player_id }
									className={ [
										'border-b border-rule-soft',
										isMe ? youRowClass : '',
									].join( ' ' ) }
								>
									<td className="num px-4 py-2.5 text-ink-3">
										{ r.rank }
									</td>
									<td className="px-4 py-2.5">
										<span className="mr-2 text-base text-ink-2">
											{ pieceForRank( r.rank, total ) }
										</span>
										{ r.player_id ? (
											<Link
												to={ `/seasons/${ seasonId }/players/${ r.player_id }` }
												className="text-ink no-underline hover:text-accent"
											>
												{ r.name ?? '—' }
											</Link>
										) : (
											<span className="text-ink">
												{ r.name ?? '—' }
											</span>
										) }
										{ isMe && <YouTag /> }
										{ r.elo ? (
											<span className="num ml-2 text-xs text-muted">
												{ r.elo }
											</span>
										) : null }
									</td>
									{ hasCat && (
										<td className="px-4 py-2.5 text-ink-3">
											{ r.category ?? '—' }
										</td>
									) }
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ r.games }
									</td>
									{ hasScore && (
										<td className="num px-4 py-2.5 font-mono text-ink">
											{ r.keizer_score ?? '—' }
										</td>
									) }
									<td className="num px-4 py-2.5 font-mono">
										{ Number.isFinite(
											Number( r.classical_points )
										)
											? Number(
													r.classical_points
											  ).toFixed( 1 )
											: '—' }
									</td>
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ r.wins }
									</td>
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ r.draws }
									</td>
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ r.losses }
									</td>
									{ hasByes && (
										<td className="num px-4 py-2.5 font-mono text-ink-3">
											{ r.byes }
										</td>
									) }
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ Number.isFinite( r.color_balance )
											? `${ r.color_balance > 0 ? '+' : '' }${ r.color_balance }`
											: '—' }
									</td>
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ r.tpr ?? '—' }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
		</Wrap>
	);
}

function Wrap( { children, rounds, categories, category, onCategory } ) {
	const title =
		rounds > 0
			? `Standings (after ${ rounds } ${
					rounds === 1 ? 'round' : 'rounds'
			  })`
			: 'Standings';
	return (
		<Page>
			<div className="mb-6 flex flex-wrap items-center justify-between gap-4">
				<h1 className="font-serif text-[38px] leading-[1.1]">
					{ title }
				</h1>
				{ categories && categories.length > 2 && (
					<div className="flex flex-wrap gap-1">
						{ categories.map( ( c ) => (
							<button
								key={ c }
								type="button"
								onClick={ () => onCategory( c ) }
								className={ [
									'rounded-full border px-3 py-1 text-sm',
									c === category
										? 'border-accent bg-accent-soft text-accent-2'
										: 'border-rule text-ink-3 hover:text-ink',
								].join( ' ' ) }
							>
								{ c }
							</button>
						) ) }
					</div>
				) }
			</div>
			{ children }
		</Page>
	);
}

function SortTh( { col, sort, onSort, className = '' } ) {
	const active = sort.key === col.key;
	return (
		<th className={ `px-4 py-2 font-medium ${ className }` }>
			<button
				type="button"
				onClick={ () => onSort( col ) }
				className="inline-flex items-center gap-1 uppercase tracking-wide text-muted hover:text-ink"
			>
				{ col.label }
				{ active && <span>{ sort.dir === 'asc' ? '▲' : '▼' }</span> }
			</button>
		</th>
	);
}
