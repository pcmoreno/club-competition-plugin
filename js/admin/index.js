import { createRoot } from '@wordpress/element';
import '../../css/tailwind.css';

// Admin management sub-app. Compiled to build/admin.js as a separate bundle.
// Not enqueued yet — the admin flows (Tournaments, Players, run-the-round
// wizard) are a later build; several are still PARKED in dev/page-inventory.md.
// The viewer's "Admin" tab is the in-app entry point for now.
const mount = document.getElementById( 'scs-admin' );
if ( mount ) {
	createRoot( mount ).render(
		<div className="mx-auto max-w-page px-7 py-8 text-ink-3">
			Admin sub-app — not built yet.
		</div>
	);
}
