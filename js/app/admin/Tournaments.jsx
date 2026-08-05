import { useState } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Link } from '../router/router';
import { AdminHeader } from './AdminLayout';
import { ImportSeasonDialog } from './ImportSeasonDialog';
import { CreateTournamentDialog } from './CreateTournamentDialog';
import { PAIRING_LABELS, STATUS_LABELS } from './tournamentShared';
import {
	Notice,
	formatDate,
	ActionsMenu,
	SearchInput,
} from '../components/ui';
import { keys } from '../api/keys';

// ADMIN. List of tournaments (= seasons), grouped Active / Preparation /
// Completed, from GET /seasons. This screen only lists and creates — everything
// about a single tournament, settings and delete included, lives on its detail
// page (/admin/tournaments/:id), reached by clicking the name.

// Display order of the status groups.
const GROUPS = [
	{ status: 'active', label: 'Active' },
	{ status: 'preparation', label: 'Preparation' },
	{ status: 'completed', label: 'Completed' },
];

export function Tournaments() {
	const [ importing, setImporting ] = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const { data, isLoading, isError } = useQuery( {
		queryKey: keys.seasons(),
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
		const q = search.trim().toLowerCase();
		const filtered = data.filter(
			( s ) => ! q || ( s.name ?? '' ).toLowerCase().includes( q )
		);
		if ( filtered.length === 0 ) {
			content = <Notice>No tournaments match your search.</Notice>;
		} else {
			content = (
				<div className="flex flex-col gap-8">
					{ GROUPS.map( ( g ) => {
						const rows = filtered.filter(
							( s ) => s.status === g.status
						);
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
	}

	return (
		<>
			<AdminHeader
				title="Tournaments"
				action={
					<div className="flex items-center gap-2">
						<ActionsMenu
							items={ [
								{
									label: 'Create tournament',
									onClick: () => setCreating( true ),
								},
								{
									label: 'Import season',
									onClick: () => setImporting( true ),
								},
							] }
						/>
						<SearchInput
							value={ search }
							onChange={ setSearch }
							placeholder="Search name…"
						/>
					</div>
				}
			/>
			{ content }
			{ creating && (
				<CreateTournamentDialog
					onClose={ () => setCreating( false ) }
				/>
			) }
			{ importing && (
				<ImportSeasonDialog onClose={ () => setImporting( false ) } />
			) }
		</>
	);
}

// Tournaments without an end date come first (still open-ended), then the rest
// newest end date first.
function byEndDate( a, b ) {
	if ( ! a.end_date || ! b.end_date ) {
		return ( a.end_date ? 1 : 0 ) - ( b.end_date ? 1 : 0 );
	}
	return b.end_date.localeCompare( a.end_date );
}

function TournamentGroup( { label, rows } ) {
	const sorted = [ ...rows ].sort( byEndDate );
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
							<th className="px-4 py-2 font-medium">Players</th>
							<th className="px-4 py-2 font-medium">Pairing</th>
							<th className="px-4 py-2 font-medium">Dates</th>
							<th className="px-4 py-2 font-medium">Status</th>
						</tr>
					</thead>
					<tbody>
						{ sorted.map( ( s ) => (
							<tr
								key={ s.id }
								className="border-b border-rule-soft"
							>
								<td className="px-4 py-2.5">
									<Link
										to={ `/admin/tournaments/${ s.id }` }
										className="text-ink hover:text-accent hover:underline"
									>
										{ s.name }
									</Link>
								</td>
								<td className="num px-4 py-2.5 font-mono text-ink-3">
									{ s.player_count ?? 0 }
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
								<td className="px-4 py-2.5 text-ink-3">
									{ STATUS_LABELS[ s.status ] ?? s.status }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
}
