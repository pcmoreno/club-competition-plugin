// Shared presentation for rendering a game's players and result, used by the
// Pairings and Round history views (and later Player detail).

// Small chess-square swatch matching the hi-fi design system.
export function Square( { color } ) {
	return (
		<span
			className={ [
				'inline-block h-3 w-3 rounded-[2px] border border-rule align-middle',
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
