import { useAuth } from '../auth/AuthContext';
import { navigate } from '../router/router';
import { TournamentSwitcher } from './TournamentSwitcher';

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

export function TopBar( { seasonId, onSeasonChange } ) {
	const { isMember, logout } = useAuth();

	return (
		<header className="border-b border-rule bg-paper">
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
					<TournamentSwitcher
						value={ seasonId }
						onChange={ onSeasonChange }
					/>
					{ isMember ? (
						<button
							type="button"
							className="text-sm text-ink-3 hover:text-ink"
							onClick={ logout }
						>
							Sign out
						</button>
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
	);
}
