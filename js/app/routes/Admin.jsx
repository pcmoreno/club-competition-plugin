import { Placeholder } from '../layout/Page';

// ADMIN. Entry to the admin sub-app (its own left sidebar: Tournaments,
// Players). Built as a separate `admin` bundle later; this tab is the
// in-viewer entry point. See dev/page-inventory.md → Admin sub-app.
export function Admin() {
	return (
		<Placeholder title="Admin">
			Admin sub-app (Tournaments, Players, the run-the-round wizard) ships
			as a separate bundle. Several flows are still PARKED (enrollment,
			round-wizard step order, sidebar extras). — not built yet.
		</Placeholder>
	);
}
