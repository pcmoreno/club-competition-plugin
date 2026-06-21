// Pure derivations over a player's games list, shared by the tournament-detail
// and (later) the global player-detail views. A "game" here is the shape the
// player endpoints return: { round_number, color, result, is_bye, opponent }
// where result is the player's own POV ('win' | 'loss' | 'draw' | null).
//
// Only decided games (win/draw/loss) score; byes and unplayed rounds are
// skipped — they neither count toward a record nor break a streak.

export function record( games ) {
	let wins = 0;
	let draws = 0;
	let losses = 0;
	for ( const g of games ) {
		if ( g.result === 'win' ) wins++;
		else if ( g.result === 'draw' ) draws++;
		else if ( g.result === 'loss' ) losses++;
	}
	return { wins, draws, losses, decided: wins + draws + losses };
}

// Linear performance rating over rated opponents: average opponent rating
// ± 400 per net win. Null when no rated opponent was faced.
export function performance( games ) {
	const rated = games.filter( ( g ) => g.opponent && g.opponent.rating > 0 );
	if ( rated.length === 0 ) {
		return null;
	}
	const avg =
		rated.reduce( ( s, g ) => s + g.opponent.rating, 0 ) / rated.length;
	let net = 0;
	for ( const g of rated ) {
		if ( g.result === 'win' ) net++;
		else if ( g.result === 'loss' ) net--;
	}
	return Math.round( avg + ( 400 * net ) / rated.length );
}

// Longest consecutive runs, each as { length, from, to } where from/to are the
// bounding games (so the view can show the dates the run spanned). A bye /
// unplayed round is skipped, so it doesn't break a run (the games either side
// stay consecutive).
export function streaks( games ) {
	const blank = () => ( { length: 0, from: null, to: null } );
	const best = { win: blank(), unbeaten: blank(), loss: blank() };
	const cur = { win: null, unbeaten: null, loss: null };

	const bump = ( type, g, active ) => {
		if ( ! active ) {
			cur[ type ] = null;
			return;
		}
		if ( ! cur[ type ] ) {
			cur[ type ] = { length: 0, from: g, to: g };
		}
		cur[ type ].length++;
		cur[ type ].to = g;
		if ( cur[ type ].length > best[ type ].length ) {
			best[ type ] = { ...cur[ type ] };
		}
	};

	for ( const g of games ) {
		if ( g.result === 'win' ) {
			bump( 'win', g, true );
			bump( 'unbeaten', g, true );
			bump( 'loss', g, false );
		} else if ( g.result === 'draw' ) {
			bump( 'win', g, false );
			bump( 'unbeaten', g, true );
			bump( 'loss', g, false );
		} else if ( g.result === 'loss' ) {
			bump( 'win', g, false );
			bump( 'unbeaten', g, false );
			bump( 'loss', g, true );
		}
		// bye / unplayed: skip, preserving the active runs.
	}
	return best;
}

// The player's active trailing run, classified by their most recent decided
// game: a win → 'winning', a draw → 'unbeaten', a loss → 'losing'. Null when
// they've played no decided game. Shown for in-progress seasons.
export function currentStreak( games ) {
	const decided = games.filter(
		( g ) =>
			g.result === 'win' || g.result === 'draw' || g.result === 'loss'
	);
	if ( decided.length === 0 ) {
		return null;
	}
	const last = decided[ decided.length - 1 ].result;
	let type;
	let matches;
	if ( last === 'loss' ) {
		type = 'losing';
		matches = ( r ) => r === 'loss';
	} else if ( last === 'win' ) {
		type = 'winning';
		matches = ( r ) => r === 'win';
	} else {
		type = 'unbeaten';
		matches = ( r ) => r === 'win' || r === 'draw';
	}
	let i = decided.length - 1;
	while ( i >= 0 && matches( decided[ i ].result ) ) {
		i--;
	}
	const run = decided.slice( i + 1 );
	return {
		type,
		length: run.length,
		from: run[ 0 ],
		to: run[ run.length - 1 ],
	};
}

// The win against the highest-rated opponent and the loss against the
// lowest-rated, by opponent start-of-tournament rating. Either may be null.
export function extremes( games ) {
	let bestWin = null;
	let worstLoss = null;
	for ( const g of games ) {
		if ( ! g.opponent || ! ( g.opponent.rating > 0 ) ) continue;
		if (
			g.result === 'win' &&
			( ! bestWin || g.opponent.rating > bestWin.opponent.rating )
		) {
			bestWin = g;
		}
		if (
			g.result === 'loss' &&
			( ! worstLoss || g.opponent.rating < worstLoss.opponent.rating )
		) {
			worstLoss = g;
		}
	}
	return { bestWin, worstLoss };
}
