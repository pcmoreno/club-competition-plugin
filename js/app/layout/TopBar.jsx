import { useEffect, useRef, useState } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '../auth/AuthContext';
import { api } from '../api/client';
import { navigate } from '../router/router';
import { BootstrapAdminDialog } from '../auth/BootstrapAdminDialog';
import { TournamentSwitcher } from './TournamentSwitcher';
import { keys } from '../api/keys';

/** Brand mark: a tiny 2×2 chessboard, matching the hi-fi `.brand-mark`. */
function BrandMark() {
	return (
		<span className="grid h-8 w-8 grid-cols-2 overflow-hidden rounded-sm border-[1.5px] border-ink">
			<span className="bg-white-sq" />
			<span className="bg-black-sq" />
			<span className="bg-black-sq" />
			<span className="bg-white-sq" />
		</span>
	);
}

// Signed-in account control: shows the user's own email as a plain text
// button that opens a dropdown with Account Details + Sign out.
// Account Details is a placeholder for now — it has no destination yet.
// Player Details is member-only: admins have no player record, so it's
// hidden for them.
function AccountMenu( { email, logout, isAdmin } ) {
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

	const itemClass =
		'block w-full px-3 py-2 text-left text-sm text-ink-2 hover:bg-rule/40 hover:text-ink focus:bg-rule/40 focus:text-ink focus:outline-none';

	return (
		<div className="relative" ref={ ref }>
			<button
				type="button"
				className="block max-w-[14rem] truncate text-sm text-ink-3 hover:text-ink"
				onClick={ () => setOpen( ( v ) => ! v ) }
				aria-haspopup="menu"
				aria-expanded={ open }
			>
				{ email || 'Account' }
			</button>
			{ open && (
				<div
					role="menu"
					className="absolute right-0 z-20 mt-1.5 w-max min-w-full overflow-hidden rounded border border-rule bg-surface py-1 shadow-md"
				>
					<button
						type="button"
						role="menuitem"
						className={ itemClass }
						onClick={ () => {
							setOpen( false );
							navigate( '/account' );
						} }
					>
						Account Details
					</button>
					{ ! isAdmin && (
						<button
							type="button"
							role="menuitem"
							className={ itemClass }
							onClick={ () => {
								// No destination yet — placeholder for a future
								// player page.
								setOpen( false );
							} }
						>
							Player Details
						</button>
					) }
					<button
						type="button"
						role="menuitem"
						className={ itemClass }
						onClick={ () => {
							setOpen( false );
							logout();
						} }
					>
						Sign out
					</button>
				</div>
			) }
		</div>
	);
}

export function TopBar( { seasonId, onSeasonChange, showTournamentSwitcher } ) {
	const { isMember, isAdmin, email, logout } = useAuth();
	const [ newAdminOpen, setNewAdminOpen ] = useState( false );

	// Break-glass "New admin" entry, shown only while the site has no admin yet.
	// The server re-checks this invariant, so the button is just UX gating.
	const { data: bootstrapStatus } = useQuery( {
		queryKey: keys.adminBootstrapStatus(),
		queryFn: () => api.get( 'auth/bootstrap-status' ),
		staleTime: Infinity,
	} );
	const canBootstrap = bootstrapStatus?.available === true;

	return (
		<>
			<header className="border-b border-rule bg-paper print:hidden">
				<div className="mx-auto flex max-w-page items-center justify-between gap-6 px-7 py-3.5">
					<button
						type="button"
						onClick={ () => navigate( '/pairings' ) }
						aria-label="Go to home"
						className="flex items-center gap-3 rounded text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
					>
						<BrandMark />
						<div className="leading-tight">
							<div className="font-serif text-[19px] font-medium tracking-[-0.01em] text-ink">
								Clubcompetitie
							</div>
							<div className="text-xs uppercase tracking-[0.08em] text-muted">
								Schaakclub Santpoort
							</div>
						</div>
					</button>

					<div className="flex items-center gap-4">
						{ showTournamentSwitcher && (
							<TournamentSwitcher
								value={ seasonId }
								onChange={ onSeasonChange }
							/>
						) }
						{ canBootstrap && (
							<button
								type="button"
								className="rounded border border-accent px-3 py-1.5 text-sm font-medium text-accent hover:bg-accent hover:text-paper"
								onClick={ () => setNewAdminOpen( true ) }
							>
								New admin
							</button>
						) }
						{ isMember ? (
							<AccountMenu
								email={ email }
								logout={ logout }
								isAdmin={ isAdmin }
							/>
						) : (
							<button
								type="button"
								className="text-sm text-ink-3 hover:text-ink"
								onClick={ () => navigate( '/login' ) }
							>
								Sign in
							</button>
						) }
					</div>
				</div>
			</header>
			{ newAdminOpen && (
				<BootstrapAdminDialog
					onClose={ () => setNewAdminOpen( false ) }
				/>
			) }
		</>
	);
}
