import { Placeholder } from '../layout/Page';
import { Link } from '../router/router';

// MEMBER. Header (name, category, KNSB Elo+ID), next-round band, KPI tiles
// (rank, Keizer, W/D/L, games), form bar, games table. KPIs/form blocked on
// ScoringService; header + games backable by GET /players/{id}.
export function PlayerDetail( { playerId } ) {
	return (
		<Placeholder title="Player">
			<p>
				Detail for player #{ playerId }: header, next-round band, KPI
				tiles, form bar, games table. KPIs/form blocked on
				ScoringService. — view not built yet.
			</p>
			<p className="mt-3">
				<Link to="/players">← Back to players</Link>
			</p>
		</Placeholder>
	);
}
