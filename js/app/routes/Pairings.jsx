import { Placeholder } from '../layout/Page';

// PUBLIC. Shows the most-recent non-draft round of the selected tournament:
// pairings at `published`, results at `complete`. Member-only "I won't be
// present next round" action when the next round is still draft.
// Backable today by GET /seasons/{id}/rounds + GET /rounds/{id} (+ games).
export function Pairings( { seasonId } ) {
	return (
		<Placeholder title="Pairings">
			Most-recent round pairings/results for the selected tournament
			{ seasonId ? ` (#${ seasonId })` : '' }. Board · White · Result ·
			Black · Cat, with print/PDF and the member absence action. — view
			not built yet.
		</Placeholder>
	);
}
