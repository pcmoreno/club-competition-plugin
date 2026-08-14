/**
 * The single source of truth for react-query cache keys.
 *
 * Keys used to be written inline at each call site, which let the same resource
 * be cached twice under keys that differ only by type: `SerializerService`
 * emits `id` as a PHP int (so a JSON number), while the router's `matchPath`
 * always yields strings. `['round', 5]` and `['round', '5']` are different
 * cache entries, so an admin invalidating one never touched the other — the
 * viewer kept serving a stale round after a publish, and every such resource
 * was fetched twice.
 *
 * Every id is therefore normalised through `id()` here, once, and nothing
 * outside this module should build a key by hand.
 */

// Ids may arrive as a number (API payload), a string (route param) or as
// null/undefined while a parent query is still loading. Missing stays missing —
// coercing it would produce NaN, which is not a stable key.
const id = ( value ) =>
	value === null || value === undefined ? null : Number( value );

export const keys = {
	// Season / tournament
	seasons: () => [ 'seasons' ],
	season: ( seasonId ) => [ 'season', id( seasonId ) ],
	seasonSettings: ( seasonId ) => [ 'season-settings', id( seasonId ) ],
	seasonContacts: ( seasonId ) => [ 'season-contacts', id( seasonId ) ],
	seasonAbsences: ( seasonId ) => [ 'season-absences', id( seasonId ) ],

	// Rounds
	rounds: ( seasonId ) => [ 'rounds', id( seasonId ) ],
	round: ( roundId ) => [ 'round', id( roundId ) ],

	// Standings. GET /seasons/{id}/standings?round=… is one endpoint, so it gets
	// one key shape: `roundId` null means "latest". Passing no argument yields
	// the `['standings']` prefix, which invalidates every season and round.
	standings: ( seasonId, roundId = null ) =>
		seasonId === undefined
			? [ 'standings' ]
			: [ 'standings', id( seasonId ), id( roundId ) ],

	// Players
	adminPlayers: () => [ 'admin-players' ],
	playerTournaments: ( playerId ) => [ 'player-tournaments', id( playerId ) ],
	playerTournament: ( seasonId, playerId ) => [
		'player-tournament',
		id( seasonId ),
		id( playerId ),
	],

	// The signed-in member's own view (GET /me/home).
	home: () => [ 'me-home' ],

	// The plugin's own admin accounts (GET /admins) — every status, shared by
	// the Admins tab and the contacts picker (which filters to active).
	admins: () => [ 'admins' ],

	// Account / setup
	account: () => [ 'account' ],
	adminBootstrapStatus: () => [ 'admin-bootstrap-status' ],
	knsbStatus: () => [ 'knsb-status' ],
	fixtures: () => [ 'fixtures' ],
};
