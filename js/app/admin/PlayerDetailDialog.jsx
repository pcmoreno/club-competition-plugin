import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { Notice } from '../components/ui';

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';
const dangerBtn =
	'rounded border border-loss/40 px-3 py-1.5 text-sm font-medium text-loss hover:bg-loss/10';

// Equal-width action buttons that sit two-to-a-row and fill the section width
// (flex-1). Used for Edit/Activate in the Player section and Invite/Revoke in
// the Member section, so a single button spans full width and a pair splits 50/50.
const splitBase = 'flex-1 rounded px-4 py-2 text-center text-sm font-medium';
const splitPrimaryBtn = `${ splitBase } bg-ink text-paper hover:bg-ink-2`;
const splitDangerBtn = `${ splitBase } border border-loss/40 text-loss hover:bg-loss/10`;
const splitSubtleBtn = `${ splitBase } border border-rule text-ink-3 hover:text-ink hover:border-ink-3`;

function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

// The season a player is enrolled in maps to one of these statuses; label them
// for admins in the tournaments list.
const SEASON_STATUS_LABEL = {
	preparation: 'Preparation',
	active: 'Active',
	completed: 'Completed',
};

// ADMIN. Read-first player overview opened by clicking a name on the roster.
// Three sections — Player (details + activate/deactivate), Tournaments (the
// seasons they're enrolled in), Member (login account: invite / revoke). Edits
// are deliberate: the four plain fields go through the separate EditPlayerDialog
// (opened via onEdit), and invites through InviteMemberDialog (via onInvite).
//
// The player row is read live from the ['admin-players'] roster cache (already
// loaded — we came from it), so activate/deactivate, invite and revoke all
// reflect the moment their mutation invalidates that query, without this dialog
// holding its own stale snapshot.
export function PlayerDetailDialog( { playerId, onClose, onEdit, onInvite } ) {
	const { data: roster } = useQuery( {
		queryKey: [ 'admin-players' ],
		queryFn: () => api.get( 'players' ),
	} );
	const player = Array.isArray( roster )
		? roster.find( ( p ) => p.id === playerId )
		: null;

	// Lifted here (not only in TournamentsSection) because the delete affordance
	// is gated on it: a player can be deleted only when enrolled in nothing.
	const tournamentsQuery = useQuery( {
		queryKey: [ 'player-tournaments', playerId ],
		queryFn: () => api.get( `players/${ playerId }/tournaments` ),
	} );
	const canDelete =
		tournamentsQuery.isSuccess &&
		Array.isArray( tournamentsQuery.data ) &&
		tournamentsQuery.data.length === 0;

	// 'active' | 'revoke' | 'delete' | null — which confirmation is showing on top.
	const [ confirm, setConfirm ] = useState( null );

	if ( ! player ) {
		return null;
	}

	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onClose }
		>
			<div
				className="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="flex items-start justify-between gap-4">
					<h2 className="font-serif text-2xl leading-tight">
						{ player.name }
					</h2>
					<div className="flex shrink-0 items-center gap-2">
						{ ! player.active && (
							<span className="mt-1 rounded bg-loss/10 px-2 py-0.5 text-xs font-medium text-loss">
								Inactive
							</span>
						) }
						{ canDelete && (
							<button
								type="button"
								className="rounded p-1.5 text-loss hover:bg-loss/10"
								onClick={ () => setConfirm( 'delete' ) }
								aria-label={ `Delete ${ player.name }` }
								title="Delete player"
							>
								<TrashIcon className="h-6 w-6" />
							</button>
						) }
					</div>
				</div>

				<PlayerSection
					player={ player }
					onEdit={ () => onEdit( player ) }
					onToggleActive={ () => setConfirm( 'active' ) }
				/>
				<TournamentsSection query={ tournamentsQuery } playerId={ player.id } />
				<MemberSection
					player={ player }
					onInvite={ () => onInvite( player ) }
					onRevoke={ () => setConfirm( 'revoke' ) }
				/>

				<div className="mt-6 flex justify-end border-t border-rule-soft pt-4">
					<button
						type="button"
						className={ ghostBtn }
						onClick={ onClose }
					>
						Close
					</button>
				</div>
			</div>

			{ confirm === 'active' && (
				<ToggleActiveConfirm
					player={ player }
					onClose={ () => setConfirm( null ) }
				/>
			) }
			{ confirm === 'revoke' && (
				<RevokeConfirm
					player={ player }
					onClose={ () => setConfirm( null ) }
				/>
			) }
			{ confirm === 'delete' && (
				<DeleteConfirm
					player={ player }
					onClose={ () => setConfirm( null ) }
					onDeleted={ onClose }
				/>
			) }
		</div>
	);
}

function SectionHeading( { children } ) {
	return (
		<h3 className="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-muted">
			{ children }
		</h3>
	);
}

function DetailRow( { label, value, mono = false } ) {
	return (
		<div className="flex items-baseline justify-between gap-3 py-1">
			<dt className="text-sm text-muted">{ label }</dt>
			<dd
				className={ [
					'text-right text-sm text-ink',
					mono ? 'num font-mono' : '',
				].join( ' ' ) }
			>
				{ value === null || value === undefined || value === ''
					? '—'
					: value }
			</dd>
		</div>
	);
}

function PlayerSection( { player, onEdit, onToggleActive } ) {
	return (
		<section>
			<SectionHeading>Player</SectionHeading>
			<dl className="rounded border border-rule bg-surface px-4 py-2">
				<DetailRow label="KNSB Elo" value={ player.knsb_elo } mono />
				<DetailRow label="KNSB ID" value={ player.knsb_id } mono />
				<DetailRow label="Birth year" value={ player.birth_year } mono />
				<DetailRow label="Gender" value={ player.gender } />
			</dl>
			<div className="mt-2 flex gap-2">
				<button
					type="button"
					className={ splitPrimaryBtn }
					onClick={ onEdit }
				>
					Edit
				</button>
				<button
					type="button"
					className={ player.active ? splitDangerBtn : splitSubtleBtn }
					onClick={ onToggleActive }
				>
					{ player.active ? 'Deactivate' : 'Activate' }
				</button>
			</div>
		</section>
	);
}

function TournamentsSection( { query, playerId } ) {
	const { data, isLoading, isError } = query;

	let body;
	if ( isLoading ) {
		body = <Notice>Loading…</Notice>;
	} else if ( isError || ! Array.isArray( data ) ) {
		body = <Notice>Couldn’t load tournaments.</Notice>;
	} else if ( data.length === 0 ) {
		body = (
			<p className="rounded border border-rule bg-surface px-4 py-3 text-sm text-muted">
				Not enrolled in any tournaments yet.
			</p>
		);
	} else {
		body = (
			<ul className="max-h-52 divide-y divide-rule-soft overflow-y-auto rounded border border-rule bg-surface">
				{ data.map( ( t ) => (
					<li
						key={ t.season_id }
						className="flex items-center justify-between gap-3 px-4 py-2.5"
					>
						<a
							href={ `#/seasons/${ t.season_id }/players/${ playerId }` }
							target="_blank"
							rel="noopener noreferrer"
							className="text-sm text-ink underline-offset-2 hover:text-accent hover:underline"
						>
							{ t.season_name }
						</a>
						<span className="shrink-0 text-xs uppercase tracking-wide text-muted">
							{ SEASON_STATUS_LABEL[ t.season_status ] ??
								t.season_status }
						</span>
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<section>
			<SectionHeading>Tournaments</SectionHeading>
			{ body }
		</section>
	);
}

// Human labels + styling per member account status. `null` = the player has no
// login account at all.
const MEMBER_BADGE = {
	invited: { label: 'Invited', cls: 'bg-ink/10 text-ink-3' },
	active: { label: 'Active', cls: 'bg-win/10 text-win' },
	revoked: { label: 'Revoked', cls: 'bg-loss/10 text-loss' },
};

function MemberSection( { player, onInvite, onRevoke } ) {
	const status = player.member_status ?? null;
	const badge = status ? MEMBER_BADGE[ status ] : null;

	// Invite is offered when there's no account, a pending invite (resend), or a
	// revoked account (re-invite). Revoke is offered for any live account
	// (invited or active) — a revoked one has nothing left to revoke.
	const canInvite =
		status === null || status === 'invited' || status === 'revoked';
	const canRevoke = status === 'invited' || status === 'active';
	const inviteLabel =
		status === 'invited'
			? 'Resend invite'
			: status === 'revoked'
			? 'Re-invite'
			: 'Send invite';

	return (
		<section>
			<SectionHeading>Member account</SectionHeading>
			<div className="rounded border border-rule bg-surface px-4 py-3">
				{ status === null ? (
					<p className="text-sm text-muted">
						No member account — this player can’t log in.
					</p>
				) : (
					<div className="flex items-center justify-between gap-3">
						<span className="text-sm text-ink">
							{ player.email ?? '—' }
						</span>
						{ badge && (
							<span
								className={ `shrink-0 rounded px-2 py-0.5 text-xs font-medium ${ badge.cls }` }
							>
								{ badge.label }
							</span>
						) }
					</div>
				) }
			</div>
			{ ( canInvite || canRevoke ) && (
				<div className="mt-2 flex gap-2">
					{ canInvite && (
						<button
							type="button"
							className={ splitSubtleBtn }
							onClick={ onInvite }
						>
							{ inviteLabel }
						</button>
					) }
					{ canRevoke && (
						<button
							type="button"
							className={ splitDangerBtn }
							onClick={ onRevoke }
						>
							Revoke
						</button>
					) }
				</div>
			) }
		</section>
	);
}

// Small confirmation overlay stacked above the detail dialog. Renders its own
// backdrop (clicking it, or Cancel, closes only the confirmation); z above the
// detail modal so it captures the interaction.
function ConfirmOverlay( { children, onClose } ) {
	return (
		<div
			className="fixed inset-0 z-[60] flex items-center justify-center bg-ink/40 p-4"
			onClick={ ( e ) => {
				// Stop the click from bubbling to the detail modal's backdrop
				// below, which would close the whole modal — dismiss only the
				// confirmation.
				e.stopPropagation();
				onClose();
			} }
		>
			<div
				className="w-full max-w-sm rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				{ children }
			</div>
		</div>
	);
}

function ToggleActiveConfirm( { player, onClose } ) {
	const queryClient = useQueryClient();
	const deactivating = player.active;
	const toggle = useMutation( {
		mutationFn: () =>
			api.patch( `players/${ player.id }`, { active: ! player.active } ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'admin-players' ] } );
			onClose();
		},
	} );

	return (
		<ConfirmOverlay onClose={ onClose }>
			<h2 className="font-serif text-2xl leading-tight">
				{ deactivating ? 'Deactivate player' : 'Activate player' }
			</h2>
			<p className="mt-2 text-sm text-ink-3">
				{ deactivating ? (
					<>
						Deactivate{ ' ' }
						<strong className="text-ink">{ player.name }</strong>? They
						stay in the roster and their history is kept, but they
						won’t be offered for new pairings.
					</>
				) : (
					<>
						Reactivate{ ' ' }
						<strong className="text-ink">{ player.name }</strong> so
						they can be paired again?
					</>
				) }
			</p>
			{ toggle.isError && (
				<p className="mt-3 text-sm text-loss">
					{ errorMessage( toggle.error ) }
				</p>
			) }
			<div className="mt-5 flex justify-end gap-2">
				<button
					type="button"
					className={ ghostBtn }
					onClick={ onClose }
					disabled={ toggle.isPending }
				>
					Cancel
				</button>
				<button
					type="button"
					className={ primaryBtn }
					onClick={ () => toggle.mutate() }
					disabled={ toggle.isPending }
				>
					{ toggle.isPending
						? 'Saving…'
						: deactivating
						? 'Deactivate'
						: 'Activate' }
				</button>
			</div>
		</ConfirmOverlay>
	);
}

function RevokeConfirm( { player, onClose } ) {
	const queryClient = useQueryClient();
	const revoke = useMutation( {
		mutationFn: () => api.post( `players/${ player.id }/revoke` ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'admin-players' ] } );
			onClose();
		},
	} );

	return (
		<ConfirmOverlay onClose={ onClose }>
			<h2 className="font-serif text-2xl leading-tight">
				Revoke member access
			</h2>
			<p className="mt-2 text-sm text-ink-3">
				Revoke the member account for{ ' ' }
				<strong className="text-ink">{ player.name }</strong>? They’re
				logged out immediately and can’t sign in again until re-invited.
				The player and their history are kept.
			</p>
			{ revoke.isError && (
				<p className="mt-3 text-sm text-loss">
					{ errorMessage( revoke.error ) }
				</p>
			) }
			<div className="mt-5 flex justify-end gap-2">
				<button
					type="button"
					className={ ghostBtn }
					onClick={ onClose }
					disabled={ revoke.isPending }
				>
					Cancel
				</button>
				<button
					type="button"
					className={ dangerBtn }
					onClick={ () => revoke.mutate() }
					disabled={ revoke.isPending }
				>
					{ revoke.isPending ? 'Revoking…' : 'Revoke' }
				</button>
			</div>
		</ConfirmOverlay>
	);
}

// Permanent, irreversible delete — only reachable when the player has no
// tournaments (the trash affordance is hidden otherwise, and the backend
// re-checks). On success the player is gone from the roster, so close the whole
// detail modal via onDeleted before refreshing it.
function DeleteConfirm( { player, onClose, onDeleted } ) {
	const queryClient = useQueryClient();
	const del = useMutation( {
		mutationFn: () => api.del( `players/${ player.id }` ),
		onSuccess: () => {
			onDeleted();
			queryClient.invalidateQueries( { queryKey: [ 'admin-players' ] } );
		},
	} );

	return (
		<ConfirmOverlay onClose={ onClose }>
			<h2 className="font-serif text-2xl leading-tight">Delete player</h2>
			<p className="mt-2 text-sm text-ink-3">
				Permanently delete{ ' ' }
				<strong className="text-ink">{ player.name }</strong>? This also
				removes their member account and login. This can’t be undone.
			</p>
			{ del.isError && (
				<p className="mt-3 text-sm text-loss">
					{ errorMessage( del.error ) }
				</p>
			) }
			<div className="mt-5 flex justify-end gap-2">
				<button
					type="button"
					className={ ghostBtn }
					onClick={ onClose }
					disabled={ del.isPending }
				>
					Cancel
				</button>
				<button
					type="button"
					className={ dangerBtn }
					onClick={ () => del.mutate() }
					disabled={ del.isPending }
				>
					{ del.isPending ? 'Deleting…' : 'Delete' }
				</button>
			</div>
		</ConfirmOverlay>
	);
}

function TrashIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
		>
			<path d="M4 7h16" />
			<path d="M10 11v6M14 11v6" />
			<path d="M5 7l1 13a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1l1-13" />
			<path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" />
		</svg>
	);
}
