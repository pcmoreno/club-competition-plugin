import { ApiError } from '../api/client';

// Shared building blocks for the tournament admin screens (Create dialog,
// detail-page tabs). Single source for the pairing options, status labels and
// the common form styles so the create flow and the edit tabs stay in step.

// PairingSystem enum (src/Entity/Enum/PairingSystem.php). Scoring is derived
// from the pairing system on the backend, so it isn't chosen separately.
//
// Keizer is not selectable: it's the one system whose scoring has no strategy
// yet, so a Keizer season can't complete a round. The backend rejects it too
// (PairingSystem::implementedValues); the label stays here so existing seasons
// still render a name. Automatic pairing is unavailable for every system —
// boards are built by hand — which is why the others remain selectable.
export const PAIRING_OPTIONS = [
	{ value: 'keizer', label: 'Keizer', implemented: false },
	{ value: 'swiss', label: 'Swiss' },
	{ value: 'manual', label: 'Manual' },
	{ value: 'round-robin-full', label: 'Round-robin' },
	{ value: 'round-robin-groups', label: 'Round-robin (groups)' },
];

// The system a fresh tournament starts on.
export const DEFAULT_PAIRING_SYSTEM = 'manual';

export const PAIRING_LABELS = Object.fromEntries(
	PAIRING_OPTIONS.map( ( o ) => [ o.value, o.label ] )
);

// SeasonStatus enum (src/Entity/Enum/SeasonStatus.php).
export const STATUS_LABELS = {
	preparation: 'Preparation',
	active: 'Active',
	completed: 'Completed',
};

// RoundStatus enum (src/Entity/Enum/RoundStatus.php). Pairings are editable
// while draft/published; finalised and complete lock the board.
export const ROUND_STATUS_LABELS = {
	draft: 'Draft',
	published: 'Published',
	finalised: 'Finalised',
	complete: 'Complete',
};

export const ROUND_EDITABLE = [ 'draft', 'published' ];

// Round status advances one step at a time along this path.
export const ROUND_STATUS_FLOW = [
	'draft',
	'published',
	'finalised',
	'complete',
];

// Verb for advancing *into* each status (the button label / confirm action).
export const ROUND_ADVANCE_LABELS = {
	published: 'Publish round',
	finalised: 'Finalise round',
	complete: 'Complete round',
};

export function nextRoundStatus( status ) {
	return ROUND_STATUS_FLOW[ ROUND_STATUS_FLOW.indexOf( status ) + 1 ] ?? null;
}

// Label for the "Generate pairings" action, keyed off the season's pairing
// cadence (PairingSystem::cadence()). Manual has no generator.
export function generateLabel( cadence ) {
	if ( cadence === 'full' ) {
		return 'Generate tournament pairings';
	}
	if ( cadence === 'per-round' ) {
		return 'Generate next round pairings';
	}
	return null;
}

export const fieldInput =
	'w-full rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none disabled:opacity-60';
export const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';
export const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink disabled:opacity-60';

// Turns an ApiError into a display string, unfolding field-level validation
// errors (keyed by path) into "field: message" when the backend sends them.
export function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		const errors = err.body?.data?.errors || err.body?.errors;
		if ( errors && typeof errors === 'object' ) {
			return Object.entries( errors )
				.map( ( [ key, msg ] ) => `${ key }: ${ msg }` )
				.join( ' · ' );
		}
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}
