import { Link, navigate } from '../router/router';
import { Page } from '../layout/Page';

// Admin sub-app shell. While any /admin route is active the public/member
// SubNav is hidden and replaced by this admin headerbar (same horizontal tab
// style): Tournaments · Players · Settings, with an "Exit Admin Mode" action
// on the right that returns to the viewer. Content renders in the page column
// below, via `children`.

const NAV = [
	{ to: '/admin/tournaments', label: 'Tournaments' },
	{ to: '/admin/players', label: 'Players' },
	{ to: '/admin/settings', label: 'Settings' },
];

export function AdminLayout( { activePath, children } ) {
	return (
		<>
			<nav className="border-b border-rule bg-surface">
				<div className="mx-auto flex max-w-page items-center gap-1 overflow-x-auto px-4.5">
					{ NAV.map( ( item ) => {
						const active =
							activePath === item.to ||
							activePath.startsWith( item.to + '/' );
						return (
							<Link
								key={ item.to }
								to={ item.to }
								className={ [
									'-mb-px whitespace-nowrap border-b-2 px-3.5 pb-3 pt-3.5 text-sm font-medium no-underline',
									active
										? 'border-accent text-ink'
										: 'border-transparent text-ink-3 hover:text-ink',
								].join( ' ' ) }
							>
								{ item.label }
							</Link>
						);
					} ) }

					<span
						aria-hidden="true"
						className="mx-2 h-4 w-px self-center bg-rule"
					/>

					<button
						type="button"
						onClick={ () => navigate( '/pairings' ) }
						className="whitespace-nowrap py-3 text-sm text-ink-3 hover:text-ink"
					>
						← Exit Admin Mode
					</button>
				</div>
			</nav>

			<Page>{ children }</Page>
		</>
	);
}

// Shared page header for admin views, so the title spacing stays consistent
// across Tournaments / Players / Settings.
export function AdminHeader( { title, action } ) {
	return (
		<div className="mb-6 flex items-end justify-between gap-4">
			<h1 className="font-serif text-[32px] leading-[1.1]">{ title }</h1>
			{ action }
		</div>
	);
}
