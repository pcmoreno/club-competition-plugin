import { useState } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { useAuth } from '../auth/AuthContext';
import { Notice, YouTag, youRowClass } from '../components/ui';

// PUBLIC. Pieces-ladder standings for the selected tournament, read from the
// latest frozen StandingsSnapshot (no live compute). The piece reflects the
// engine's default ranking (Keizer score) and stays attached to the player
// even when the table is re-sorted by another column — sort is a temporary
// view, the piece always shows true standing. Δrank/Movers need a prior
// snapshot and are omitted until the scoring engine produces per-round ones.

// Chess-piece glyph by rank: ♔ #1 → ♕ → ♖ → ♗ (top half) → ♘ (mid) → ♙.
function pieceForRank( rank, total ) {
	if ( rank === 1 ) {
		return '♔';
	}
	if ( rank === 2 ) {
		return '♕';
	}
	if ( rank === 3 ) {
		return '♖';
	}
	if ( rank <= total / 2 ) {
		return '♗';
	}
	if ( rank <= total * 0.75 ) {
		return '♘';
	}
	return '♙';
}

// Sortable numeric columns (name is intentionally not sortable). The score
// column is labelled engine-neutrally ("Score", not "Keizer") since other
// pairing systems rank by their own metric.
const COL = {
	rank: { key: 'rank', label: 'Rank', dir: 'asc' },
	score: { key: 'keizer_score', label: 'Score', dir: 'desc' },
	points: { key: 'classical_points', label: 'Pts', dir: 'desc' },
	byes: { key: 'byes', label: 'Byes', dir: 'desc' },
	tpr: { key: 'tpr', label: 'TPR', dir: 'desc' },
};

export function Standings( { seasonId } ) {
	const { playerId } = useAuth();
	const [ sort, setSort ] = useState( { key: 'rank', dir: 'asc' } );
	const [ category, setCategory ] = useState( 'Overall' );

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

	return (
		<Wrap
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
							<th className="w-16 px-4 py-2 font-medium">Cat</th>
							<SortTh
								col={ COL.score }
								sort={ sort }
								onSort={ onSort }
								className="w-20"
							/>
							<SortTh
								col={ COL.points }
								sort={ sort }
								onSort={ onSort }
								className="w-16"
							/>
							<th className="w-24 px-4 py-2 font-medium">
								W/D/L
							</th>
							<SortTh
								col={ COL.byes }
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
										<span className="text-ink">
											{ r.name ?? '—' }
										</span>
										{ isMe && <YouTag /> }
										<span className="num ml-2 text-xs text-muted">
											{ r.elo ? `${ r.elo }` : '' }
											{ Number.isFinite( r.color_balance )
												? ` · S ${
														r.color_balance > 0
															? '+'
															: ''
												  }${ r.color_balance }`
												: '' }
											{ ` · ${ r.games }` }
										</span>
									</td>
									<td className="px-4 py-2.5 text-ink-3">
										{ r.category ?? '—' }
									</td>
									<td className="num px-4 py-2.5 font-mono text-ink">
										{ r.keizer_score }
									</td>
									<td className="num px-4 py-2.5 font-mono">
										{ r.classical_points }
									</td>
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ r.wins }/{ r.draws }/{ r.losses }
									</td>
									<td className="num px-4 py-2.5 font-mono text-ink-3">
										{ r.byes }
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

function Wrap( { children, categories, category, onCategory } ) {
	return (
		<Page>
			<div className="mb-6 flex flex-wrap items-center justify-between gap-4">
				<h1 className="font-serif text-[38px] leading-[1.1]">
					Standings
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
