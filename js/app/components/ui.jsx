import { useState, useEffect, useRef } from '@wordpress/element';

// Page-level "Actions" dropdown, sitting left of a search box in admin list
// headers. items: [{ label, onClick, disabled? }]. Closes on outside-click or
// Escape, matching the account menu in the top bar.
export function ActionsMenu( { items, label = 'Actions' } ) {
	const [ open, setOpen ] = useState( false );
	const ref = useRef( null );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const onClick = ( e ) => {
			if ( ref.current && ! ref.current.contains( e.target ) ) {
				setOpen( false );
			}
		};
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', onClick );
		document.addEventListener( 'keydown', onKey );
		return () => {
			document.removeEventListener( 'mousedown', onClick );
			document.removeEventListener( 'keydown', onKey );
		};
	}, [ open ] );

	return (
		<div className="relative" ref={ ref }>
			<button
				type="button"
				className="inline-flex items-center gap-1.5 rounded border border-rule bg-surface px-3 py-1.5 text-sm font-medium text-ink-3 hover:text-ink"
				onClick={ () => setOpen( ( v ) => ! v ) }
				aria-haspopup="menu"
				aria-expanded={ open }
			>
				{ label }
				<ChevronIcon className="rotate-90" />
			</button>
			{ open && (
				<div
					role="menu"
					className="absolute left-0 z-20 mt-1.5 w-max min-w-full overflow-hidden rounded border border-rule bg-surface py-1 shadow-md"
				>
					{ items.map( ( item ) => (
						<button
							key={ item.label }
							type="button"
							role="menuitem"
							className="block w-full px-3 py-2 text-left text-sm text-ink-2 hover:bg-rule/40 hover:text-ink focus:bg-rule/40 focus:text-ink focus:outline-none disabled:cursor-not-allowed disabled:text-muted disabled:hover:bg-transparent"
							disabled={ item.disabled }
							onClick={ () => {
								setOpen( false );
								item.onClick();
							} }
						>
							{ item.label }
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

// Search input for admin list headers, matching the Actions dropdown height.
export function SearchInput( { value, onChange, placeholder = 'Search…' } ) {
	return (
		<input
			type="search"
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
			placeholder={ placeholder }
			className="w-56 rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none"
		/>
	);
}

function ChevronIcon( { className } ) {
	return (
		<svg
			className={ className }
			width="14"
			height="14"
			viewBox="0 0 16 16"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.4"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
		>
			<path d="m6 4 4 4-4 4" />
		</svg>
	);
}

// Generic confirmation dialog. `children` is the body copy; `danger` styles the
// confirm button red for destructive actions. Backdrop click cancels.
export function ConfirmModal( {
	title,
	children,
	confirmLabel = 'Confirm',
	onConfirm,
	onCancel,
	danger = false,
} ) {
	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onCancel }
		>
			<div
				className="w-full max-w-md rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h2 className="font-serif text-2xl leading-tight">{ title }</h2>
				<div className="mt-2 text-sm text-ink-3">{ children }</div>
				<div className="mt-6 flex justify-end gap-2">
					<button
						type="button"
						className="rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink"
						onClick={ onCancel }
					>
						Cancel
					</button>
					<button
						type="button"
						className={
							'rounded px-4 py-2 text-sm font-medium text-paper ' +
							( danger
								? 'bg-loss hover:opacity-90'
								: 'bg-ink hover:bg-ink-2' )
						}
						onClick={ onConfirm }
					>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</div>
	);
}

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
