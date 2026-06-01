// Dashed-border card for loading / error / empty / "coming later" states.
export function Notice( { children } ) {
	return (
		<div className="rounded border border-dashed border-rule bg-surface p-6 text-ink-3">
			{ children }
		</div>
	);
}

// Marks the logged-in member's own row/game.
export function YouTag() {
	return (
		<span className="ml-2 rounded-full bg-accent-soft px-1.5 py-0.5 text-[11px] font-medium text-accent-2">
			you
		</span>
	);
}

// Tailwind classes for a highlighted "this is you" table row.
export const youRowClass = 'bg-accent-soft';

// Formats a date-only 'YYYY-MM-DD' string for display. Parsed from its parts as
// a *local* date — `new Date('2026-06-01')` parses as UTC midnight, which renders
// the previous day in negative-offset timezones. Returns null on empty/invalid.
export function formatDate( ymd ) {
	if ( ! ymd ) {
		return null;
	}
	const [ y, m, d ] = String( ymd ).split( '-' ).map( Number );
	if ( ! y || ! m || ! d ) {
		return null;
	}
	return new Date( y, m - 1, d ).toLocaleDateString( undefined, {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} );
}
