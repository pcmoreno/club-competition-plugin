// Shared presentation for rendering a game's players and result, used by the
// Pairings and Round history views (and later Player detail).

// TimeControl enum (src/Entity/Enum/TimeControl.php). Lives here rather than in
// the admin's tournamentShared so the viewer can label a tournament's tempo
// without importing across the admin boundary.
export const TIME_CONTROL_OPTIONS = [
	{ value: 'classical', label: 'Classical' },
	{ value: 'rapid', label: 'Rapid' },
	{ value: 'blitz', label: 'Blitz' },
];

// The internal competition is classical, so that's where a new tournament starts.
export const DEFAULT_TIME_CONTROL = 'classical';

export const TIME_CONTROL_LABELS = Object.fromEntries(
	TIME_CONTROL_OPTIONS.map( ( o ) => [ o.value, o.label ] )
);

// Chess-piece glyph by standings rank within a field of `total` players:
// ♔ #1 → ♕ #2–3 → then ♖ ♗ ♘ split the rest of the TOP half → and the whole
// BOTTOM half is ♙ (a chess set is half pawns). Shared by the standings table
// and the player-detail header so a player's piece is the same in both.
export function pieceForRank( rank, total ) {
	if ( rank === 1 ) {
		return '♔';
	}
	if ( rank <= 3 ) {
		return '♕';
	}

	const topHalf = total / 2;
	if ( rank > topHalf ) {
		return '♙';
	}

	// Ranks 4…topHalf go to Rook → Bishop → Knight in three equal bands,
	// front-loaded (ceil) so the better ranks get the stronger piece and a lone
	// leftover slot in a small field lands on the Rook rather than the Knight.
	const band = Math.ceil( ( topHalf - 3 ) / 3 );
	if ( rank <= 3 + band ) {
		return '♖';
	}
	if ( rank <= 3 + 2 * band ) {
		return '♗';
	}
	return '♘';
}

// Small chess-square swatch matching the hi-fi design system.
export function Square( { color } ) {
	return (
		<span
			className={ [
				'inline-block h-3 w-3 rounded-[2px] border border-rule align-middle print:hidden',
				color === 'white' ? 'bg-white-sq' : 'bg-black-sq',
			].join( ' ' ) }
		/>
	);
}

// GameResult enum value → score token.
export function resultToken( result ) {
	switch ( result ) {
		case 'white':
			return '1–0';
		case 'black':
			return '0–1';
		case 'draw':
			return '½–½';
		default:
			return '·';
	}
}

// "Cat A" for a same-category pairing, "A ↔ B" for cross-category, '' if
// neither player has a category (undivided pool / guests).
export function categoryLabel( white, black ) {
	const wc = white?.category;
	const bc = black?.category;
	if ( ! wc && ! bc ) {
		return '';
	}
	if ( wc && bc && wc !== bc ) {
		return `${ wc } ↔ ${ bc }`;
	}
	return `Cat ${ wc || bc }`;
}
