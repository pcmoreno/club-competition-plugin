import { Placeholder } from '../layout/Page';

// PUBLIC. Pieces ladder (chess-piece glyph by engine rank, merged into the
// name cell), sortable on all columns except name, category filter.
// BLOCKED on ScoringService (Keizer score, classical points, %, TPR, Δrank) —
// no read endpoint exists yet.
export function Standings() {
	return (
		<Placeholder title="Standings">
			Pieces-ladder standings for the selected tournament. Blocked on
			ScoringService — Keizer score, classical points, %, TPR and Δrank
			have no backend yet. — view not built yet.
		</Placeholder>
	);
}
