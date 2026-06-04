import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { AdminHeader } from './AdminLayout';
import { Notice, formatDate } from '../components/ui';

// ADMIN. Full club roster (all players, active or not), from GET /players —
// admin-scoped because it carries email + member-account status. Editing,
// member invites and "new player" are a later pass; this step renders the list.

export function Players() {
	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'admin-players' ],
		queryFn: () => api.get( 'players' ),
	} );

	let content;
	if ( isLoading ) {
		content = <Notice>Loading…</Notice>;
	} else if ( isError || ! Array.isArray( data ) ) {
		content = <Notice>Couldn’t load players. Please try again.</Notice>;
	} else if ( data.length === 0 ) {
		content = <Notice>No players in the roster yet.</Notice>;
	} else {
		// Highest KNSB rating first; unrated sort to the bottom.
		const players = [ ...data ].sort(
			( a, b ) => ( b.knsb_elo || 0 ) - ( a.knsb_elo || 0 )
		);
		content = <RosterTable players={ players } />;
	}

	return (
		<>
			<AdminHeader title="Full Club Players List" />
			{ content }
		</>
	);
}

function RosterTable( { players } ) {
	return (
		<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
			<table className="w-full text-sm">
				<thead>
					<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
						<th className="px-4 py-2 font-medium">Name</th>
						<th className="px-4 py-2 font-medium">Email</th>
						<th className="px-4 py-2 font-medium">DOB</th>
						<th className="px-4 py-2 font-medium">Gender</th>
						<th className="px-4 py-2 text-right font-medium">
							Elo
						</th>
						<th className="px-4 py-2 font-medium">Active</th>
						<th className="px-4 py-2 font-medium">Member</th>
					</tr>
				</thead>
				<tbody>
					{ players.map( ( p ) => (
						<tr
							key={ p.id }
							className={ [
								'border-b border-rule-soft',
								p.active ? '' : 'text-muted',
							].join( ' ' ) }
						>
							<td className="px-4 py-2.5 text-ink">{ p.name }</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ p.email ?? '—' }
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ formatDate( p.date_of_birth ) ?? '—' }
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ p.gender ?? '—' }
							</td>
							<td className="num px-4 py-2.5 text-right font-mono">
								{ p.knsb_elo ? p.knsb_elo : '—' }
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ p.active ? 'Yes' : 'No' }
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ p.member_status ?? '—' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
