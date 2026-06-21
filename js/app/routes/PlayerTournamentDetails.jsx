import { useRef, useState, useLayoutEffect } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { Link } from '../router/router';
import { useAuth } from '../auth/AuthContext';
import { Notice, YouTag } from '../components/ui';
import { pieceForRank } from '../components/game';
import {
	SummaryBlock,
	BreakdownBlock,
	GamesList,
	PositionGraph,
	Highlights,
} from '../components/player/blocks';

// PUBLIC. One player's whole run through a single season: their games (left),
// a summary card, per-opponent-category and per-colour splits, streaks, a
// position-over-time graph and their best win / worst loss. Reached from the
// standings, roster and round-history player links. Opponent names link to that
// opponent's detail in the same season; the subject's own name links up to the
// global player profile. Built from reusable blocks in components/player/ so the
// global player-detail view can share them. Backed by
// GET /seasons/{seasonId}/players/{playerId}.

export function PlayerTournamentDetails( { seasonId, playerId } ) {
	const { playerId: meId } = useAuth();
	const { data, isLoading, isError, error } = useQuery( {
		queryKey: [ 'player-tournament', seasonId, playerId ],
		queryFn: () => api.get( `seasons/${ seasonId }/players/${ playerId }` ),
	} );

	if ( isLoading ) {
		return (
			<Wrap>
				<Notice>Loading…</Notice>
			</Wrap>
		);
	}
	if ( isError || ! data ) {
		const notFound = error?.status === 404;
		return (
			<Wrap>
				<Notice>
					{ notFound
						? 'This player didn’t take part in this season.'
						: 'Couldn’t load this player. Please try again.' }
				</Notice>
			</Wrap>
		);
	}

	const { season, player, games, positions } = data;
	const isMe = meId !== null && player.player_id === meId;
	const seasonInProgress = season.status !== 'completed';
	// Opponents link to their own detail within this same season.
	const opponentTo = ( opponent ) =>
		`/seasons/${ season.id }/players/${ opponent.player_id }`;

	return (
		<Wrap>
			<Header player={ player } season={ season } isMe={ isMe } />

			<SummaryBlock
				games={ games }
				rank={ player.rank }
				categoryRank={ player.category_rank }
				rating={ player.rating }
				tpr={ player.tpr }
				seasonInProgress={ seasonInProgress }
			/>

			<div className="mt-6 grid gap-6 lg:grid-cols-5">
				<div className="lg:col-span-3">
					<GamesList games={ games } opponentTo={ opponentTo } />
				</div>
				<div className="space-y-6 lg:col-span-2">
					<PositionGraph
						positions={ positions }
						fieldSize={ season.field_size }
					/>
					<Highlights games={ games } opponentTo={ opponentTo } />
					<ColourBreakdown games={ games } />
					<CategoryBreakdown
						season={ season }
						player={ player }
						games={ games }
					/>
				</div>
			</div>
		</Wrap>
	);
}

// A chess glyph sized to the two-line header height. Rendered centered in a
// square, overflow-visible SVG so the wider pieces (the queen) get horizontal
// room and the taller ones (the bishop) aren't clipped vertically. The font-size
// is tuned so the average piece spans the box; minor per-glyph height variance
// is inherent to the font.
function PieceGlyph( { glyph, height } ) {
	const px = height || 56;
	return (
		<svg
			aria-hidden="true"
			className="shrink-0 fill-ink-2"
			style={ { height: px, width: px, overflow: 'visible' } }
			viewBox="0 0 100 100"
		>
			<text
				x="50"
				y="50"
				textAnchor="middle"
				dominantBaseline="central"
				style={ { fontSize: '116px' } }
			>
				{ glyph }
			</text>
		</svg>
	);
}

function Wrap( { children } ) {
	return (
		<Page>
			<div className="mb-4">
				<Link
					to="/standings"
					className="text-sm text-ink-3 hover:text-ink"
				>
					← Back to standings
				</Link>
			</div>
			{ children }
		</Page>
	);
}

// Name + season/category, with the player's standings piece (♔…♙) to the left,
// sized to exactly the height of the two header lines (measured, since a glyph's
// visual height isn't its font-size). The subject's own name graduates to the
// global player profile.
function Header( { player, season, isMe } ) {
	const piece =
		player.rank != null
			? pieceForRank( player.rank, season.field_size || 1 )
			: null;
	const textRef = useRef( null );
	const [ height, setHeight ] = useState( null );
	useLayoutEffect( () => {
		if ( textRef.current ) {
			setHeight( textRef.current.offsetHeight );
		}
	}, [ player.name, season.name, player.category ] );

	return (
		<div className="mb-6 flex items-center gap-4">
			{ piece && <PieceGlyph glyph={ piece } height={ height } /> }
			<div ref={ textRef }>
				<h1 className="font-serif text-[38px] leading-[1.1]">
					<Link
						to={ `/players/${ player.player_id }` }
						className="text-ink hover:text-accent"
					>
						{ player.name ?? '—' }
					</Link>
					{ isMe && <YouTag /> }
				</h1>
				<p className="mt-1 text-sm text-ink-3">
					{ season.name }
					{ player.category ? ` · Category ${ player.category }` : '' }
				</p>
			</div>
		</div>
	);
}

// "As White" / "As Black" — the same breakdown over the games the player held
// each colour in. Stacked in the right column.
function ColourBreakdown( { games } ) {
	const white = games.filter( ( g ) => g.color === 'white' );
	const black = games.filter( ( g ) => g.color === 'black' );
	if ( white.length === 0 && black.length === 0 ) {
		return null;
	}
	return (
		<>
			<BreakdownBlock title="As White" games={ white } />
			<BreakdownBlock title="As Black" games={ black } />
		</>
	);
}

// One breakdown block per opponent category the player faced — only when the
// season runs categories (an undivided pool collapses to nothing, the summary
// covers it). A B-player gets up to three (A/B/C); an A-player typically fewer.
function CategoryBreakdown( { season, player, games } ) {
	if ( ! season.categories || season.categories.length === 0 ) {
		return null;
	}
	const byCat = {};
	for ( const g of games ) {
		const cat = g.opponent?.category;
		if ( ! cat ) continue;
		( byCat[ cat ] ??= [] ).push( g );
	}
	const cats = Object.keys( byCat ).sort();
	if ( cats.length === 0 ) {
		return null;
	}

	return (
		<>
			{ cats.map( ( cat ) => (
				<BreakdownBlock
					key={ cat }
					title={ `vs Category ${ cat }` }
					subtitle={
						player.category && player.category !== cat
							? 'cross-category'
							: null
					}
					games={ byCat[ cat ] }
				/>
			) ) }
		</>
	);
}
