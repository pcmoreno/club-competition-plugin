// Reusable presentation blocks for a player's games/standing, composed by the
// tournament-detail view and (later) the global player-detail view. Each block
// is fed a games list in the player-endpoint shape and is routing-agnostic:
// where an opponent links to is supplied by an `opponentTo( opponent )` prop,
// so the same block can link within a season or to a global profile.

import { Link } from '../../router/router';
import { Notice } from '../ui';
import { Square } from '../game';
import {
	record,
	performance,
	streaks,
	currentStreak,
	extremes,
} from './stats';

export function OpponentLink( { opponent, opponentTo } ) {
	if ( ! opponent ) {
		return <span className="text-ink-3">—</span>;
	}
	const to = opponentTo?.( opponent );
	const label = opponent.name ?? '—';
	if ( ! to ) {
		return <span className="text-ink">{ label }</span>;
	}
	return (
		<Link to={ to } className="text-ink no-underline hover:text-accent">
			{ label }
		</Link>
	);
}

// ── Streaks ──────────────────────────────────────────────────────────────────

// A streak's date range, e.g. "16 Sep – 30 Sep" (or a single date for a run of
// one). Falls back to "Rd N" when a game has no date.
function shortDate( ymd ) {
	if ( ! ymd ) {
		return null;
	}
	const [ y, m, d ] = String( ymd ).split( '-' ).map( Number );
	if ( ! y || ! m || ! d ) {
		return null;
	}
	return new Date( y, m - 1, d ).toLocaleDateString( undefined, {
		day: 'numeric',
		month: 'short',
	} );
}

function pointLabel( g ) {
	if ( ! g ) {
		return '';
	}
	return shortDate( g.date ) || `Rd ${ g.round_number }`;
}

function rangeLabel( streak ) {
	const a = pointLabel( streak.from );
	const b = pointLabel( streak.to );
	return a === b ? a : `${ a } – ${ b }`;
}

const TONE = { win: 'text-win', loss: 'text-loss', ink: 'text-ink-2' };

function StreakRow( { label, tone, length, detail } ) {
	return (
		<div className="flex items-baseline gap-2">
			<span className="w-20 shrink-0 text-ink-3">{ label }</span>
			<span className={ `num w-5 font-mono font-medium ${ TONE[ tone ] }` }>
				{ length }
			</span>
			{ length > 0 && detail && (
				<span className="text-xs text-muted">{ detail }</span>
			) }
		</div>
	);
}

// The three longest runs (with dates) and, for an in-progress season, the
// active "Current" run.
export function LongestStreaks( { games, current } ) {
	const s = streaks( games );
	return (
		<div>
			<div className="text-xs uppercase tracking-wide text-muted">
				Longest streaks
			</div>
			<dl className="mt-2 space-y-1 text-sm">
				<StreakRow
					label="Winning"
					tone="win"
					length={ s.win.length }
					detail={ rangeLabel( s.win ) }
				/>
				<StreakRow
					label="Unbeaten"
					tone="ink"
					length={ s.unbeaten.length }
					detail={ rangeLabel( s.unbeaten ) }
				/>
				<StreakRow
					label="Losing"
					tone="loss"
					length={ s.loss.length }
					detail={ rangeLabel( s.loss ) }
				/>
				{ current && current.length > 0 && (
					<div className="mt-1 border-t border-rule-soft pt-1">
						<StreakRow
							label="Current"
							tone={
								current.type === 'winning'
									? 'win'
									: current.type === 'losing'
									? 'loss'
									: 'ink'
							}
							length={ current.length }
							detail={ current.type }
						/>
					</div>
				) }
			</dl>
		</div>
	);
}

// ── Figures ──────────────────────────────────────────────────────────────────

function Stat( { label, value, hint } ) {
	return (
		<div>
			<dt className="text-xs uppercase tracking-wide text-muted">
				{ label }
			</dt>
			<dd className="num mt-0.5 font-mono text-lg text-ink">
				{ value }
				{ hint && (
					<span className="ml-1.5 font-sans text-xs text-muted">
						{ hint }
					</span>
				) }
			</dd>
		</div>
	);
}

// label · value row, value right-aligned — the vertical W/D/L list.
function FigureRow( { label, value } ) {
	return (
		<div className="flex items-baseline justify-between gap-4">
			<span className="text-ink-3">{ label }</span>
			<span className="num font-mono font-medium text-ink">{ value }</span>
		</div>
	);
}

// value · label row, value first — the breakdown mini-tables.
function ValueRow( { label, value } ) {
	return (
		<div className="flex items-baseline gap-3">
			<span className="num w-10 shrink-0 text-right font-mono font-medium text-ink">
				{ value }
			</span>
			<span className="text-ink-3">{ label }</span>
		</div>
	);
}

// ── Summary (header) block ───────────────────────────────────────────────────

// The whole-tournament headline on a single wrapping row: rating, overall
// position, position within category, performance (official TPR), a vertical
// W/D/L, and the longest streaks (plus the current run while the season is in
// progress).
export function SummaryBlock( {
	games,
	rank,
	categoryRank,
	rating,
	tpr,
	seasonInProgress = false,
} ) {
	const { wins, draws, losses, decided } = record( games );
	const perf = tpr ?? performance( games );
	const current = seasonInProgress ? currentStreak( games ) : null;

	return (
		<div className="rounded border border-rule bg-surface p-5 shadow-sm">
			<div className="flex flex-wrap items-center justify-between gap-x-8 gap-y-6">
				<Stat label="Rating" value={ rating || '—' } />
				<Stat
					label="Position"
					value={ rank != null ? `#${ rank }` : '—' }
				/>
				{ categoryRank != null && (
					<Stat label="Cat position" value={ `#${ categoryRank }` } />
				) }
				<Stat
					label="Performance"
					value={ perf != null ? perf : '—' }
					hint={
						decided > 0
							? `${ decided } game${ decided === 1 ? '' : 's' }`
							: null
					}
				/>
				<dl className="min-w-[8rem] space-y-1 text-sm">
					<FigureRow label="Wins" value={ wins } />
					<FigureRow label="Draws" value={ draws } />
					<FigureRow label="Losses" value={ losses } />
				</dl>
				<LongestStreaks games={ games } current={ current } />
			</div>
		</div>
	);
}

// ── Breakdown block (per category / colour) ──────────────────────────────────

// A Wins/Draws/Losses + performance mini-table (left, 2/5) beside the longest
// streaks (right, 3/5), spanning the full block width. Used for the per-category
// and As White / As Black splits, where there's no official subset TPR so
// performance is computed.
export function BreakdownBlock( { title, subtitle, games } ) {
	const { wins, draws, losses } = record( games );
	const perf = performance( games );

	return (
		<div className="rounded border border-rule bg-surface p-5 shadow-sm">
			<div className="flex items-baseline justify-between gap-3">
				<h2 className="font-serif text-xl">{ title }</h2>
				{ subtitle && (
					<span className="text-sm text-ink-3">{ subtitle }</span>
				) }
			</div>

			<div className="mt-3 grid gap-6 sm:grid-cols-5">
				<dl className="space-y-1 text-sm sm:col-span-2">
					<ValueRow label="Wins" value={ wins } />
					<ValueRow label="Draws" value={ draws } />
					<ValueRow label="Losses" value={ losses } />
					<div className="border-t border-rule-soft pt-1">
						<ValueRow
							label="Performance"
							value={ perf != null ? perf : '—' }
						/>
					</div>
				</dl>
				<div className="sm:col-span-3">
					<LongestStreaks games={ games } />
				</div>
			</div>
		</div>
	);
}

// ── Highlights (best win / worst loss) ───────────────────────────────────────

function Highlight( { label, tone, game, opponentTo } ) {
	return (
		<div>
			<div
				className={ `text-xs uppercase tracking-wide ${
					tone === 'win' ? 'text-win' : 'text-loss'
				}` }
			>
				{ label }
			</div>
			{ game ? (
				<div className="mt-0.5 flex items-baseline justify-between gap-2">
					<OpponentLink
						opponent={ game.opponent }
						opponentTo={ opponentTo }
					/>
					<span className="num font-mono text-sm text-ink-3">
						{ game.opponent.rating } · Rd { game.round_number }
					</span>
				</div>
			) : (
				<div className="mt-0.5 text-sm text-ink-3">— none yet</div>
			) }
		</div>
	);
}

export function Highlights( { games, opponentTo } ) {
	const { bestWin, worstLoss } = extremes( games );
	if ( ! bestWin && ! worstLoss ) {
		return null;
	}
	return (
		<div className="rounded border border-rule bg-surface p-5 shadow-sm">
			<div className="space-y-3">
				<Highlight
					label="Best win"
					tone="win"
					game={ bestWin }
					opponentTo={ opponentTo }
				/>
				<Highlight
					label="Worst loss"
					tone="loss"
					game={ worstLoss }
					opponentTo={ opponentTo }
				/>
			</div>
		</div>
	);
}

// ── Position graph ───────────────────────────────────────────────────────────

// Inline SVG line of the player's standings position per round, on an ABSOLUTE
// Y axis from #1 (top) to the field size (bottom) — so the line's height shows
// true standing in the whole field, not just the player's own min–max band. No
// charting dependency — the series is small and the shape is all that matters.
export function PositionGraph( {
	positions,
	fieldSize,
	title = 'Position by round',
} ) {
	if ( ! positions || positions.length < 2 ) {
		return null;
	}
	const W = 320;
	const H = 120;
	const pad = { t: 10, r: 10, b: 18, l: 26 };
	const rounds = positions.map( ( p ) => p.round_number );
	const minR = Math.min( ...rounds );
	const maxR = Math.max( ...rounds );
	// Absolute scale: 1 … fieldSize. Fall back to the worst rank seen if the
	// field size is missing, so the graph still renders sensibly.
	const total = Math.max(
		2,
		fieldSize || Math.max( ...positions.map( ( p ) => p.rank ) )
	);
	const x = ( r ) =>
		pad.l +
		( ( r - minR ) / Math.max( 1, maxR - minR ) ) * ( W - pad.l - pad.r );
	// rank 1 → top, rank = total → bottom.
	const y = ( rank ) =>
		pad.t + ( ( rank - 1 ) / ( total - 1 ) ) * ( H - pad.t - pad.b );
	const points = positions.map(
		( p ) => `${ x( p.round_number ) },${ y( p.rank ) }`
	);
	const last = positions[ positions.length - 1 ];

	return (
		<div className="rounded border border-rule bg-surface p-5 shadow-sm">
			<div className="flex items-baseline justify-between gap-3">
				<h2 className="font-serif text-xl">{ title }</h2>
				<span className="num font-mono text-sm text-ink-3">
					#{ last.rank } / { total }
				</span>
			</div>
			<svg
				viewBox={ `0 0 ${ W } ${ H }` }
				className="mt-3 w-full"
				role="img"
				aria-label="Standings position per round"
			>
				{ [ 1, total ].map( ( rank ) => (
					<g key={ rank }>
						<line
							x1={ pad.l }
							x2={ W - pad.r }
							y1={ y( rank ) }
							y2={ y( rank ) }
							className="stroke-rule-soft"
							strokeWidth="1"
						/>
						<text
							x={ pad.l - 4 }
							y={ y( rank ) + 3 }
							textAnchor="end"
							className="fill-muted"
							style={ { fontSize: '9px' } }
						>
							{ rank }
						</text>
					</g>
				) ) }
				<polyline
					points={ points.join( ' ' ) }
					fill="none"
					className="stroke-accent"
					strokeWidth="2"
					strokeLinejoin="round"
					strokeLinecap="round"
				/>
				<circle
					cx={ x( last.round_number ) }
					cy={ y( last.rank ) }
					r="3"
					className="fill-accent"
				/>
				<text
					x={ pad.l }
					y={ H - 4 }
					className="fill-muted"
					style={ { fontSize: '9px' } }
				>
					Rd { minR }
				</text>
				<text
					x={ W - pad.r }
					y={ H - 4 }
					textAnchor="end"
					className="fill-muted"
					style={ { fontSize: '9px' } }
				>
					Rd { maxR }
				</text>
			</svg>
		</div>
	);
}

// ── Games list ───────────────────────────────────────────────────────────────

function ResultPill( { game } ) {
	if ( game.is_bye ) {
		return <span className="text-sm text-ink-3">Bye</span>;
	}
	// Uniform neutral chip; the outcome is carried by the text colour. (The
	// var-based palette doesn't support `/opacity` tints, so no coloured fills.)
	const map = {
		win: [ 'Win', 'text-accent-2' ],
		draw: [ 'Draw', 'text-ink-2' ],
		loss: [ 'Loss', 'text-loss' ],
	};
	const [ label, cls ] = map[ game.result ] ?? [ '—', 'text-ink-3' ];
	return (
		<span
			className={ `inline-block rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium ${ cls }` }
		>
			{ label }
		</span>
	);
}

// The player's games as a table: round, colour, opponent (linked), opponent
// rating, own-POV result. `emptyLabel` covers the no-games case.
export function GamesList( {
	games,
	opponentTo,
	title = 'Games',
	emptyLabel = 'No games played yet.',
} ) {
	if ( ! games || games.length === 0 ) {
		return <Notice>{ emptyLabel }</Notice>;
	}
	return (
		<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
			<div className="border-b border-rule px-5 py-3.5">
				<h2 className="font-serif text-xl">{ title }</h2>
			</div>
			<table className="w-full text-sm">
				<thead>
					<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
						<th className="w-12 px-4 py-2 font-medium">Rd</th>
						<th className="w-10 px-2 py-2 font-medium">Col</th>
						<th className="px-4 py-2 font-medium">Opponent</th>
						<th className="w-20 px-4 py-2 text-right font-medium">
							Rating
						</th>
						<th className="w-20 px-4 py-2 text-center font-medium">
							Result
						</th>
					</tr>
				</thead>
				<tbody>
					{ games.map( ( g, i ) => (
						<tr
							key={ `${ g.round_number }-${ i }` }
							className="border-b border-rule-soft"
						>
							<td className="num px-4 py-2.5 text-ink-3">
								{ g.round_number }
							</td>
							<td className="px-2 py-2.5">
								{ g.color && <Square color={ g.color } /> }
							</td>
							<td className="px-4 py-2.5">
								{ g.is_bye ? (
									<span className="text-ink-3">
										Bye (no pairing)
									</span>
								) : (
									<OpponentLink
										opponent={ g.opponent }
										opponentTo={ opponentTo }
									/>
								) }
							</td>
							<td className="num px-4 py-2.5 text-right font-mono text-ink-3">
								{ g.opponent?.rating ? g.opponent.rating : '—' }
							</td>
							<td className="px-4 py-2.5 text-center">
								<ResultPill game={ g } />
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
