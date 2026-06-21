import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { AdminHeader } from './AdminLayout';
import { Notice, formatDate } from '../components/ui';

function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

// ADMIN. Full club roster (all players, active or not), from GET /players —
// admin-scoped because it carries email + member-account status. Searchable by
// name, sortable by name/Elo. The "Synced" column shows when the KNSB rating
// sync last refreshed each player (NULL → "never"); anything not from the
// current month is flagged red (the sync runs monthly), and a player with a
// KNSB id can be (eventually) re-synced from there. Editing, member invites and
// "new player" are a later pass.

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';

// Parse the 'Y-m-d H:i:s' sync timestamp to a Date (or null for never-synced).
function syncedDate( dt ) {
	if ( ! dt ) {
		return null;
	}
	const d = new Date( String( dt ).replace( ' ', 'T' ) );
	return isNaN( d.getTime() ) ? null : d;
}

// Synced this calendar month? (false for never-synced → flagged stale/red.)
function isCurrentMonth( d ) {
	if ( ! d ) {
		return false;
	}
	const now = new Date();
	return (
		d.getFullYear() === now.getFullYear() &&
		d.getMonth() === now.getMonth()
	);
}

function syncedLabel( dt ) {
	const d = syncedDate( dt );
	if ( ! d ) {
		return 'never';
	}
	return d.toLocaleDateString( undefined, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} );
}

export function Players() {
	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'admin-players' ],
		queryFn: () => api.get( 'players' ),
	} );
	const [ search, setSearch ] = useState( '' );
	const [ sort, setSort ] = useState( { key: 'knsb_elo', dir: 'desc' } );
	const [ syncTarget, setSyncTarget ] = useState( null );

	let content;
	if ( isLoading ) {
		content = <Notice>Loading…</Notice>;
	} else if ( isError || ! Array.isArray( data ) ) {
		content = <Notice>Couldn’t load players. Please try again.</Notice>;
	} else {
		const q = search.trim().toLowerCase();
		const filtered = data.filter(
			( p ) => ! q || ( p.name ?? '' ).toLowerCase().includes( q )
		);
		const sorted = [ ...filtered ].sort( ( a, b ) => {
			if ( sort.key === 'name' ) {
				const r = ( a.name ?? '' ).localeCompare( b.name ?? '' );
				return sort.dir === 'asc' ? r : -r;
			}
			const av = a.knsb_elo || 0;
			const bv = b.knsb_elo || 0;
			return sort.dir === 'asc' ? av - bv : bv - av;
		} );

		if ( filtered.length === 0 ) {
			content = (
				<Notice>
					{ data.length === 0
						? 'No players in the roster yet.'
						: 'No players match your search.' }
				</Notice>
			);
		} else {
			content = (
				<RosterTable
					players={ sorted }
					sort={ sort }
					onSort={ setSort }
					onSync={ setSyncTarget }
				/>
			);
		}
	}

	return (
		<>
			<AdminHeader
				title="Full Club Players List"
				action={
					<input
						type="search"
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
						placeholder="Search name…"
						className="w-56 rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none"
					/>
				}
			/>
			{ content }
			{ syncTarget && (
				<SyncDialog
					player={ syncTarget }
					onClose={ () => setSyncTarget( null ) }
				/>
			) }
		</>
	);
}

function SortHeader( { label, col, sort, onSort, align = 'left', width } ) {
	const active = sort.key === col.key;
	const toggle = () =>
		onSort(
			active
				? { key: col.key, dir: sort.dir === 'asc' ? 'desc' : 'asc' }
				: { key: col.key, dir: col.dir }
		);
	return (
		<th className={ `px-4 py-2 ${ width ?? '' }` }>
			<button
				type="button"
				onClick={ toggle }
				className={ [
					'flex w-full items-center gap-1 text-xs uppercase tracking-wide text-muted hover:text-ink',
					align === 'right' ? 'justify-end' : '',
				].join( ' ' ) }
			>
				{ label }
				{ active && <span>{ sort.dir === 'asc' ? '▲' : '▼' }</span> }
			</button>
		</th>
	);
}

function RosterTable( { players, sort, onSort, onSync } ) {
	return (
		<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
			<table className="w-full text-sm">
				<thead>
					<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
						<SortHeader
							label="Name"
							col={ { key: 'name', dir: 'asc' } }
							sort={ sort }
							onSort={ onSort }
						/>
						<th className="px-4 py-2 font-medium">Email</th>
						<th className="px-4 py-2 font-medium">DOB</th>
						<th className="px-4 py-2 font-medium">Gender</th>
						<th className="px-4 py-2 font-medium">KNSB ID</th>
						<SortHeader
							label="Elo"
							col={ { key: 'knsb_elo', dir: 'desc' } }
							sort={ sort }
							onSort={ onSort }
							align="right"
						/>
						<th className="px-4 py-2 font-medium">Active</th>
						<th className="px-4 py-2 font-medium">Member</th>
						<th className="px-4 py-2 font-medium">Synced</th>
					</tr>
				</thead>
				<tbody>
					{ players.map( ( p ) => {
						const stale = ! isCurrentMonth(
							syncedDate( p.knsb_synced_at )
						);
						const label = syncedLabel( p.knsb_synced_at );
						const canSync = Boolean( p.knsb_id );
						return (
							<tr
								key={ p.id }
								className={ [
									'border-b border-rule-soft',
									p.active ? '' : 'text-muted',
								].join( ' ' ) }
							>
								<td className="px-4 py-2.5 text-ink">
									{ p.name }
								</td>
								<td className="px-4 py-2.5 text-ink-3">
									{ p.email ?? '—' }
								</td>
								<td className="px-4 py-2.5 text-ink-3">
									{ formatDate( p.date_of_birth ) ?? '—' }
								</td>
								<td className="px-4 py-2.5 text-ink-3">
									{ p.gender ?? '—' }
								</td>
								<td className="num px-4 py-2.5 font-mono text-ink-3">
									{ p.knsb_id ?? '—' }
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
								<td className="px-4 py-2.5">
									{ canSync ? (
										<button
											type="button"
											onClick={ () => onSync( p ) }
											className={ [
												'underline-offset-2 hover:underline',
												stale
													? 'text-loss'
													: 'text-ink-3',
											].join( ' ' ) }
										>
											{ label }
										</button>
									) : (
										<span
											className={
												stale
													? 'text-loss'
													: 'text-ink-3'
											}
										>
											{ label }
										</span>
									) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}

// Confirm dialog that applies the player's rating from the last-fetched KNSB
// list (POST /players/{id}/knsb-rating). On success it shows old → new and
// refreshes the roster; errors (no list fetched, id not listed) surface inline.
function SyncDialog( { player, onClose } ) {
	const queryClient = useQueryClient();
	const sync = useMutation( {
		mutationFn: () => api.post( `players/${ player.id }/knsb-rating` ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: [ 'admin-players' ] } ),
	} );
	const updated = sync.data;

	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onClose }
		>
			<div
				className="w-full max-w-sm rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h2 className="font-serif text-2xl leading-tight">
					Sync rating
				</h2>

				{ updated ? (
					<>
						<p className="mt-2 text-sm text-ink-3">
							Updated{ ' ' }
							<strong className="text-ink">
								{ player.name }
							</strong>
							:{ ' ' }
							<span className="num font-mono text-ink">
								{ player.knsb_elo ?? '—' } →{ ' ' }
								{ updated.knsb_elo }
							</span>
						</p>
						<div className="mt-5 flex justify-end">
							<button
								type="button"
								className={ primaryBtn }
								onClick={ onClose }
							>
								Done
							</button>
						</div>
					</>
				) : (
					<>
						<p className="mt-2 text-sm text-ink-3">
							Do you want to sync the rating for{ ' ' }
							<strong className="text-ink">
								{ player.name }
							</strong>{ ' ' }
							from the latest KNSB list?
						</p>
						{ sync.isError && (
							<p className="mt-3 text-sm text-loss">
								{ errorMessage( sync.error ) }
							</p>
						) }
						<div className="mt-5 flex justify-end gap-2">
							<button
								type="button"
								className={ ghostBtn }
								onClick={ onClose }
								disabled={ sync.isPending }
							>
								Back
							</button>
							<button
								type="button"
								className={ primaryBtn }
								onClick={ () => sync.mutate() }
								disabled={ sync.isPending }
							>
								{ sync.isPending ? 'Syncing…' : 'Yes' }
							</button>
						</div>
					</>
				) }
			</div>
		</div>
	);
}
