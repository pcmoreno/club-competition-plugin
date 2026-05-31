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
