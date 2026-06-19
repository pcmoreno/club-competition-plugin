import { useAuth } from '../auth/AuthContext';
import { Link } from '../router/router';

/**
 * Role-gated page tabs. Access model (see dev/page-inventory.md):
 *   public  → Pairings, Standings
 *   member  → + Round history, Players
 *   admin   → + Admin (carries a lock badge; admin sub-app is a later build)
 */
const TABS = [
	{ to: '/pairings', label: 'Pairings', need: 'public' },
	{ to: '/standings', label: 'Standings', need: 'public' },
	{ to: '/rounds', label: 'Round history', need: 'member' },
	{ to: '/players', label: 'Players', need: 'member' },
	{ to: '/admin', label: 'Admin', need: 'admin', lock: true },
];

export function SubNav( { activePath } ) {
	const { isMember, isAdmin } = useAuth();

	const visible = TABS.filter( ( t ) => {
		if ( t.need === 'member' ) {
			return isMember;
		}
		if ( t.need === 'admin' ) {
			return isAdmin;
		}
		return true;
	} );

	return (
		<nav className="border-b border-rule bg-surface">
			<div className="mx-auto flex max-w-page items-center gap-1 overflow-x-auto overflow-y-hidden px-4.5">
				{ visible.map( ( t ) => {
					const active =
						activePath === t.to ||
						activePath.startsWith( t.to + '/' );
					return (
						<Link
							key={ t.to }
							to={ t.to }
							className={ [
								'-mb-px whitespace-nowrap border-b-2 px-3.5 pb-3 pt-3.5 text-sm font-medium no-underline',
								active
									? 'border-accent text-ink'
									: 'border-transparent text-ink-3 hover:text-ink',
							].join( ' ' ) }
						>
							{ t.label }
							{ t.lock && (
								<span className="ml-1.5 text-[11px] text-muted">
									🔒
								</span>
							) }
						</Link>
					);
				} ) }
			</div>
		</nav>
	);
}
