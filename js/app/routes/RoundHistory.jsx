import { Placeholder } from '../layout/Page';

// MEMBER. Classic two-column: Games card + "Standings after Rd N" snapshot and
// Movers, with a round navigator. Snapshots/movers need wp_scs_rankings +
// ScoringService; the games side is backable by GET /rounds/{id} today.
export function RoundHistory( { seasonId } ) {
	return (
		<Placeholder title="Round history">
			Per-round games for tournament{ seasonId ? ` #${ seasonId }` : '' }{ ' ' }
			plus standings-after snapshot and movers. Snapshots/movers blocked
			on wp_scs_rankings + ScoringService. — view not built yet.
		</Placeholder>
	);
}
