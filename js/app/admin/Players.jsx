import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { AdminHeader } from './AdminLayout';
import { PlayerDialog } from './PlayerDialog';
import { PlayerDetailDialog } from './PlayerDetailDialog';
import { InviteMemberDialog } from './InviteMemberDialog';
import { MergePlayersDialog } from './MergePlayersDialog';
import { FetchKnsbDialog } from './FetchKnsbDialog';
import { SyncKnsbDialog } from './SyncKnsbDialog';
import { Dialog } from '../components/Dialog';
import { Notice, ActionsMenu, SearchInput, ChangeRow } from '../components/ui';
import { keys } from '../api/keys';

function errorMessage( err ) {
	// Our typed exceptions (404/403/409/429) return a curated, user-safe message
	// in body.error — e.g. the KNSB sync's "No KNSB rating list has been fetched
	// yet." The shared ApiError layer only reads body.message, so read body.error
	// here so the real reason reaches the admin instead of "Request failed (409)".
	if ( err instanceof ApiError && err.body?.error ) {
		return err.body.error;
	}
	return 'Something went wrong. Please try again.';
}

// ADMIN. Full club roster (all players, active or not), from GET /players —
// admin-scoped because it carries email + member-account status. Searchable by
// name, sortable by name/Elo. The "Synced" column shows when the KNSB rating
// sync last refreshed each player (NULL → "never"); anything not from the
// current month is flagged red (the sync runs monthly), and a player with a
// KNSB id can be re-synced from there. The Actions menu syncs the whole roster
// in one go (SyncKnsbDialog).
//
// Clicking the name cell opens PlayerDetailDialog — a read-first overview with
// three sections (Player details + activate/deactivate, Tournaments, Member
// account + invite/revoke). Editing is deliberate from there: the four plain
// Player fields (name, KNSB id, birth year, gender) go through PlayerDialog via
// PATCH /players/{id}. Email lives on the separate Member account, not the
// Player, so it is NOT editable here. Elo is owned by the KNSB sync flow
// (SyncDialog), so it is not editable here either.
//
// Adding a player uses the same dialog with no player to edit, and POSTs to
// /players — which creates them active, and without an Elo until a KNSB sync
// gives them one.

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
		d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth()
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
		queryKey: keys.adminPlayers(),
		queryFn: () => api.get( 'players' ),
	} );
	const [ search, setSearch ] = useState( '' );
	const [ sort, setSort ] = useState( { key: 'knsb_elo', dir: 'desc' } );
	const [ syncTarget, setSyncTarget ] = useState( null );
	const [ detailTarget, setDetailTarget ] = useState( null );
	const [ editTarget, setEditTarget ] = useState( null );
	const [ inviteTarget, setInviteTarget ] = useState( null );
	const [ addOpen, setAddOpen ] = useState( false );
	const [ mergeOpen, setMergeOpen ] = useState( false );
	const [ fetchKnsbOpen, setFetchKnsbOpen ] = useState( false );
	const [ syncKnsbOpen, setSyncKnsbOpen ] = useState( false );

	// The merge picker needs at least two players to choose between.
	const canMerge = Array.isArray( data ) && data.length >= 2;

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
					onOpen={ setDetailTarget }
					onInvite={ setInviteTarget }
				/>
			);
		}
	}

	return (
		<>
			<AdminHeader
				title="Full Club Players List"
				action={
					<div className="flex items-center gap-2">
						<ActionsMenu
							items={ [
								{
									label: 'Add player',
									onClick: () => setAddOpen( true ),
								},
								{
									label: 'Merge players',
									onClick: () => setMergeOpen( true ),
									disabled: ! canMerge,
								},
								{
									label: 'Fetch KNSB ratings',
									onClick: () => setFetchKnsbOpen( true ),
								},
								{
									label: 'Sync KNSB ratings',
									onClick: () => setSyncKnsbOpen( true ),
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
			{ syncTarget && (
				<SyncDialog
					player={ syncTarget }
					onClose={ () => setSyncTarget( null ) }
				/>
			) }
			{ detailTarget && (
				<PlayerDetailDialog
					playerId={ detailTarget.id }
					onClose={ () => setDetailTarget( null ) }
					onEdit={ setEditTarget }
					onInvite={ setInviteTarget }
				/>
			) }
			{ addOpen && <PlayerDialog onClose={ () => setAddOpen( false ) } /> }
			{ editTarget && (
				<PlayerDialog
					player={ editTarget }
					onClose={ () => setEditTarget( null ) }
				/>
			) }
			{ inviteTarget && (
				<InviteMemberDialog
					player={ inviteTarget }
					onClose={ () => setInviteTarget( null ) }
				/>
			) }
			{ mergeOpen && (
				<MergePlayersDialog
					players={ Array.isArray( data ) ? data : [] }
					onClose={ () => setMergeOpen( false ) }
				/>
			) }
			{ fetchKnsbOpen && (
				<FetchKnsbDialog onClose={ () => setFetchKnsbOpen( false ) } />
			) }
			{ syncKnsbOpen && (
				<SyncKnsbDialog onClose={ () => setSyncKnsbOpen( false ) } />
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

function RosterTable( { players, sort, onSort, onSync, onOpen, onInvite } ) {
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
						<th className="px-4 py-2 font-medium">Year</th>
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
									<button
										type="button"
										onClick={ () => onOpen( p ) }
										aria-label={ `Open ${ p.name }` }
										title="Player details"
										className="group inline-flex items-center gap-1.5 text-left text-inherit hover:text-accent"
									>
										<span className="underline-offset-2 group-hover:underline">
											{ p.name }
										</span>
										<ChevronIcon className="opacity-0 transition-opacity group-hover:opacity-100 group-focus:opacity-100" />
									</button>
								</td>
								<td className="px-4 py-2.5 text-ink-3">
									{ p.email ?? '—' }
								</td>
								<td className="num px-4 py-2.5 font-mono text-ink-3">
									{ p.birth_year ?? '—' }
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
									{ p.member_status === 'invited' ? (
										<button
											type="button"
											onClick={ () => onInvite( p ) }
											title="Resend invite"
											className="group inline-flex items-center gap-1.5 text-ink-3 hover:text-accent"
										>
											invited
											<MailIcon className="opacity-0 transition-opacity group-hover:opacity-100 group-focus:opacity-100" />
										</button>
									) : p.member_status ? (
										p.member_status
									) : (
										<button
											type="button"
											onClick={ () => onInvite( p ) }
											className="inline-flex items-center gap-1.5 text-ink-3 hover:text-accent"
										>
											<MailIcon />
											Invite
										</button>
									) }
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

// Confirm dialog that applies the player's authoritative KNSB data — name,
// birth year, and rating — from the last-fetched list (POST
// /players/{id}/knsb-rating). KNSB is the source of truth, so name (normalised
// to "given-name first") and birth year overwrite manual entries, correcting
// typos. On success it shows what changed and refreshes the roster; errors (no
// list fetched, id not listed, name collision) surface inline.
function SyncDialog( { player, onClose } ) {
	const queryClient = useQueryClient();
	const sync = useMutation( {
		mutationFn: () => api.post( `players/${ player.id }/knsb-rating` ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: keys.adminPlayers() } ),
	} );
	const updated = sync.data;

	return (
		<Dialog
			title="Sync from KNSB"
			size="sm"
			busy={ sync.isPending }
			onClose={ onClose }
		>
			{ updated ? (
				<>
					<p className="mt-2 text-sm text-ink-3">
						Synced from the KNSB list:
					</p>
					<dl className="mt-3 space-y-1.5 text-sm">
						<ChangeRow
							label="Name"
							before={ player.name }
							after={ updated.name }
						/>
						<ChangeRow
							label="Birth year"
							before={ player.birth_year }
							after={ updated.birth_year }
							mono
						/>
						<ChangeRow
							label="Rating"
							before={ player.knsb_elo }
							after={ updated.knsb_elo }
							mono
						/>
					</dl>
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
						Sync{ ' ' }
						<strong className="text-ink">{ player.name }</strong>{ ' ' }
						from the latest KNSB list? This overwrites their name,
						birth year, and rating with the official KNSB values.
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
		</Dialog>
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

function MailIcon( { className } ) {
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
			<rect x="1.5" y="3" width="13" height="10" rx="1.5" />
			<path d="m2 4 6 5 6-5" />
		</svg>
	);
}
