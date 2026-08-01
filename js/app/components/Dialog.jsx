import {
	createContext,
	useContext,
	useEffect,
	useId,
	useRef,
} from '@wordpress/element';

// Nesting depth, so a confirmation opened from inside a dialog stacks above its
// parent instead of tying with it on z-index.
const DialogDepth = createContext( 0 );

// Every open dialog, in mount order. The last one is the topmost: it alone
// answers Escape and traps Tab. The body scroll lock is released when the list
// empties, so closing an inner dialog doesn't hand scrolling back early.
const openDialogs = [];
let restoreBodyOverflow = '';

const SIZES = {
	sm: 'max-w-sm',
	md: 'max-w-md',
	lg: 'max-w-lg',
	xl: 'max-w-2xl',
};

const FOCUSABLE = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
].join( ',' );

// The shared modal shell: backdrop, panel, heading, and the behaviour every
// dialog needs and none of them had — dialog semantics for screen readers,
// Escape to close, focus moved in on open and restored on close, Tab kept
// inside, and the page behind held still.
//
// `busy` is for a write in flight: it seals the dismissal routes (Escape and
// backdrop) so a second attempt can't fire while the first is unresolved. The
// caller still disables its own buttons.
//
// Pass `onSubmit` to make the panel a form; `headerExtra` for controls that
// belong beside the title, such as a delete affordance.
export function Dialog( {
	title,
	description,
	headerExtra,
	size = 'md',
	scroll = false,
	busy = false,
	onClose,
	onSubmit,
	children,
} ) {
	const depth = useContext( DialogDepth );
	const titleId = useId();
	const descriptionId = useId();
	const panelRef = useRef( null );
	const token = useRef( {} );

	// Register in the stack and hold the page still while any dialog is open.
	useEffect( () => {
		const self = token.current;
		openDialogs.push( self );
		if ( openDialogs.length === 1 ) {
			restoreBodyOverflow = document.body.style.overflow;
			document.body.style.overflow = 'hidden';
		}
		return () => {
			const at = openDialogs.indexOf( self );
			if ( at !== -1 ) {
				openDialogs.splice( at, 1 );
			}
			if ( openDialogs.length === 0 ) {
				document.body.style.overflow = restoreBodyOverflow;
			}
		};
	}, [] );

	// Move focus in on open and give it back on close. An autoFocus'd field wins
	// — React has already focused it by now — so this only fires when the dialog
	// would otherwise leave focus behind on the trigger.
	useEffect( () => {
		const returnTo = document.activeElement;
		const panel = panelRef.current;
		if ( panel && ! panel.contains( document.activeElement ) ) {
			panel.focus();
		}
		return () => {
			if (
				returnTo instanceof HTMLElement &&
				document.contains( returnTo )
			) {
				returnTo.focus();
			}
		};
	}, [] );

	useEffect( () => {
		const onKeyDown = ( e ) => {
			if ( openDialogs[ openDialogs.length - 1 ] !== token.current ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				if ( ! busy ) {
					onClose();
				}
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			const panel = panelRef.current;
			if ( ! panel ) {
				return;
			}
			const items = Array.from( panel.querySelectorAll( FOCUSABLE ) );
			if ( items.length === 0 ) {
				e.preventDefault();
				panel.focus();
				return;
			}
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			const active = document.activeElement;
			// Wrapping at the ends is what keeps Tab from walking out into the
			// page behind, which is still fully focusable.
			if ( e.shiftKey && ( active === first || active === panel ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && active === last ) {
				e.preventDefault();
				first.focus();
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ busy, onClose ] );

	const Panel = onSubmit ? 'form' : 'div';

	return (
		<DialogDepth.Provider value={ depth + 1 }>
			<div
				className="fixed inset-0 flex items-center justify-center bg-ink/40 p-4"
				style={ { zIndex: 50 + depth * 10 } }
				onClick={ ( e ) => {
					// Only a click on the backdrop itself dismisses — not one
					// that bubbled up from the panel, or from a dialog stacked
					// on top of this one.
					if ( e.target === e.currentTarget && ! busy ) {
						onClose();
					}
				} }
			>
				<Panel
					ref={ panelRef }
					role="dialog"
					aria-modal="true"
					aria-labelledby={ titleId }
					aria-describedby={ description ? descriptionId : undefined }
					tabIndex={ -1 }
					onSubmit={ onSubmit }
					className={ [
						'w-full rounded-lg bg-paper p-6 shadow-md focus:outline-none',
						SIZES[ size ] ?? SIZES.md,
						scroll ? 'max-h-[85vh] overflow-y-auto' : '',
					]
						.filter( Boolean )
						.join( ' ' ) }
				>
					<div
						className={
							headerExtra
								? 'flex items-start justify-between gap-4'
								: undefined
						}
					>
						<div>
							<h2
								id={ titleId }
								className="font-serif text-2xl leading-tight"
							>
								{ title }
							</h2>
							{ description && (
								<p
									id={ descriptionId }
									className="mt-2 text-sm text-ink-3"
								>
									{ description }
								</p>
							) }
						</div>
						{ headerExtra && (
							<div className="flex shrink-0 items-center gap-2">
								{ headerExtra }
							</div>
						) }
					</div>
					{ children }
				</Panel>
			</div>
		</DialogDepth.Provider>
	);
}
