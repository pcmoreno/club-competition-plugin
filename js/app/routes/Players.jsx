import { Placeholder } from '../layout/Page';

// MEMBER. Plain roster (NOT standings) scoped to the selected tournament:
// Name · Cat · Elo, sorted by Elo desc; rows link to player detail.
// Backable by the season's enrolled players today.
export function Players( { seasonId } ) {
	return (
		<Placeholder title="Players">
			Roster for tournament{ seasonId ? ` #${ seasonId }` : '' } — Name ·
			Cat · Elo, sorted by Elo. Rows link to player detail. — view not
			built yet.
		</Placeholder>
	);
}
