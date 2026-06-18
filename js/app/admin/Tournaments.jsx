import { useState } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { AdminHeader } from './AdminLayout';
import { ImportSeasonDialog } from './ImportSeasonDialog';
import { Notice, formatDate } from '../components/ui';

// ADMIN. List of tournaments (= seasons), grouped Active / Preparation /
// Completed, from GET /seasons. Row counts (#players, rounds-played) and the
// "New tournament" flow are a later pass — see dev/page-inventory.md.

const PAIRING_LABELS = {
	keizer: 'Keizer',
	swiss: 'Swiss',
	'round-robin-full': 'Round-robin',
	'round-robin-groups': 'Round-robin (groups)',
};

// Display order of the status groups.
const GROUPS = [
	{ status: 'active', label: 'Active' },
	{ status: 'preparation', label: 'Preparation' },
	{ status: 'completed', label: 'Completed' },
];

export function Tournaments() {
	const [ importing, setImporting ] = useState( false );
	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'seasons' ],
		queryFn: () => api.get( 'seasons' ),
	} );

	let content;
	if ( isLoading ) {
		content = <Notice>Loading…</Notice>;
	} else if ( isError || ! Array.isArray( data ) ) {
		content = <Notice>Couldn’t load tournaments. Please try again.</Notice>;
	} else if ( data.length === 0 ) {
		content = <Notice>No tournaments yet.</Notice>;
	} else {
		content = (
			<div className="flex flex-col gap-8">
				{ GROUPS.map( ( g ) => {
					const rows = data.filter( ( s ) => s.status === g.status );
					if ( rows.length === 0 ) {
						return null;
					}
					return (
						<TournamentGroup
							key={ g.status }
							label={ g.label }
							rows={ rows }
						/>
					);
				} ) }
			</div>
		);
	}

	return (
		<>
			<AdminHeader
				title="Tournaments"
				action={
					<button
						type="button"
						className="rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2"
						onClick={ () => setImporting( true ) }
					>
						Import season
					</button>
				}
			/>
			{ content }
			{ importing && (
				<ImportSeasonDialog onClose={ () => setImporting( false ) } />
			) }
		</>
	);
}

function TournamentGroup( { label, rows } ) {
	return (
		<section>
			<h2 className="mb-2 text-xs font-medium uppercase tracking-[0.08em] text-muted">
				{ label }
			</h2>
			<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
				<table className="w-full text-sm">
					<thead>
						<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
							<th className="px-4 py-2 font-medium">Name</th>
							<th className="px-4 py-2 font-medium">Pairing</th>
							<th className="px-4 py-2 font-medium">Dates</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( s ) => (
							<tr
								key={ s.id }
								className="border-b border-rule-soft"
							>
								<td className="px-4 py-2.5 text-ink">
									{ s.name }
								</td>
								<td className="px-4 py-2.5 text-ink-3">
									{ PAIRING_LABELS[ s.pairing_system ] ??
										s.pairing_system }
								</td>
								<td className="px-4 py-2.5 text-ink-3">
									{ formatDate( s.start_date ) ?? '—' }
									{ s.end_date
										? ` – ${ formatDate( s.end_date ) }`
										: '' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
}
