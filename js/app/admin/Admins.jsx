import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { AdminHeader } from './AdminLayout';
import { InviteAdminDialog } from './InviteAdminDialog';
import { ConfirmModal, Notice, YouTag, youRowClass } from '../components/ui';
import { keys } from '../api/keys';

function errorMessage( err ) {
	if ( err instanceof ApiError && err.body?.error ) {
		return err.body.error;
	}
	return 'Something went wrong. Please try again.';
}

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';

// Formats the 'Y-m-d H:i:s' created_at to a plain date.
function createdLabel( dt ) {
	if ( ! dt ) {
		return '—';
	}
	const d = new Date( String( dt ).replace( ' ', 'T' ) );
	if ( isNaN( d.getTime() ) ) {
		return '—';
	}
	return d.toLocaleDateString( undefined, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} );
}

const STATUS_LABEL = {
	invited: 'Invited',
	active: 'Active',
	revoked: 'Revoked',
};

// ADMIN. The plugin's own admin accounts, from GET /admins.
//
// Everyone with admin access can read this list. Only the first admin account —
// the one the club was set up with — can invite new admins or remove existing
// ones, because `wp scs create-admin` isn't reachable on production and this is
// the only other way an admin account comes to exist. The backend enforces that
// rule; `is_super_admin` on each row is what it reports, so the UI doesn't
// re-derive it.
//
// An invited admin has no password until they follow their emailed link, and
// can't sign in meanwhile. The invite expires after 7 days, so pending rows
// carry a Resend action; Delete removes the account outright (there's no
// revoked-but-kept state here, unlike member accounts).
export function Admins() {
	const { data, isLoading, isError } = useQuery( {
		queryKey: keys.admins(),
		queryFn: () => api.get( 'admins' ),
	} );
	const { data: account } = useQuery( {
		queryKey: keys.account(),
		queryFn: () => api.get( 'auth/me' ),
	} );

	const [ inviteOpen, setInviteOpen ] = useState( false );
	const [ resendTarget, setResendTarget ] = useState( null );
	const [ deleteTarget, setDeleteTarget ] = useState( null );

	const admins = Array.isArray( data ) ? data : [];
	const youId = account?.id ?? null;
	const canManage = admins.some(
		( a ) => a.id === youId && a.is_super_admin
	);

	let content;
	if ( isLoading ) {
		content = <Notice>Loading…</Notice>;
	} else if ( isError ) {
		content = <Notice>Couldn’t load admins. Please try again.</Notice>;
	} else if ( admins.length === 0 ) {
		content = <Notice>No admin accounts yet.</Notice>;
	} else {
		content = (
			<AdminsTable
				admins={ admins }
				youId={ youId }
				canManage={ canManage }
				onResend={ setResendTarget }
				onDelete={ setDeleteTarget }
			/>
		);
	}

	return (
		<>
			<AdminHeader
				title="Admins"
				action={
					canManage && (
						<button
							type="button"
							className={ primaryBtn }
							onClick={ () => setInviteOpen( true ) }
						>
							Invite admin
						</button>
					)
				}
			/>

			{ content }

			{ ! isLoading && ! isError && ! canManage && (
				<p className="mt-3 text-sm text-muted">
					Only the first admin account can invite or remove admins.
				</p>
			) }

			{ inviteOpen && (
				<InviteAdminDialog onClose={ () => setInviteOpen( false ) } />
			) }
			{ resendTarget && (
				<InviteAdminDialog
					admin={ resendTarget }
					onClose={ () => setResendTarget( null ) }
				/>
			) }
			{ deleteTarget && (
				<DeleteAdminDialog
					admin={ deleteTarget }
					onClose={ () => setDeleteTarget( null ) }
				/>
			) }
		</>
	);
}

function AdminsTable( { admins, youId, canManage, onResend, onDelete } ) {
	return (
		<div className="overflow-x-auto rounded border border-rule bg-surface shadow-sm">
			<table className="w-full text-sm">
				<thead>
					<tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-muted">
						<th className="px-4 py-2 font-medium">Name</th>
						<th className="px-4 py-2 font-medium">Email</th>
						<th className="px-4 py-2 font-medium">Status</th>
						<th className="px-4 py-2 font-medium">Added</th>
						{ canManage && (
							<th className="px-4 py-2 font-medium">
								<span className="sr-only">Actions</span>
							</th>
						) }
					</tr>
				</thead>
				<tbody>
					{ admins.map( ( a ) => (
						<tr
							key={ a.id }
							className={ [
								'border-b border-rule-soft',
								a.id === youId ? youRowClass : '',
							].join( ' ' ) }
						>
							<td className="px-4 py-2.5 text-ink">
								{ a.name }
								{ a.id === youId && <YouTag /> }
								{ a.is_super_admin && (
									<span className="ml-2 text-xs text-muted">
										first admin
									</span>
								) }
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ a.email ?? '—' }
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ STATUS_LABEL[ a.status ] ?? a.status }
							</td>
							<td className="px-4 py-2.5 text-ink-3">
								{ createdLabel( a.created_at ) }
							</td>
							{ canManage && (
								<td className="px-4 py-2.5 text-right">
									<div className="flex justify-end gap-3">
										{ a.status === 'invited' && (
											<button
												type="button"
												onClick={ () => onResend( a ) }
												className="text-ink-3 underline-offset-2 hover:text-accent hover:underline"
											>
												Resend
											</button>
										) }
										{ /* The first admin is the account that
										     can delete, so it must not be
										     deletable — the backend refuses it
										     too. */ }
										{ ! a.is_super_admin && (
											<button
												type="button"
												onClick={ () => onDelete( a ) }
												className="text-ink-3 underline-offset-2 hover:text-loss hover:underline"
											>
												Delete
											</button>
										) }
									</div>
								</td>
							) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

// Removes the account outright (DELETE /admins/{id}) — the row goes, rather than
// being kept in a revoked state, and any session they hold stops working on its
// next request.
function DeleteAdminDialog( { admin, onClose } ) {
	const queryClient = useQueryClient();
	const remove = useMutation( {
		mutationFn: () => api.del( `admins/${ admin.id }` ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: keys.admins() } );
			onClose();
		},
	} );

	return (
		<ConfirmModal
			title="Delete admin"
			confirmLabel={ remove.isPending ? 'Deleting…' : 'Delete' }
			danger
			busy={ remove.isPending }
			onCancel={ onClose }
			onConfirm={ () => remove.mutate() }
		>
			<p>
				Delete <strong className="text-ink">{ admin.name }</strong>’s
				admin account? They lose access immediately, and they’re removed
				as a contact from any tournament that listed them.
			</p>
			{ remove.isError && (
				<p className="mt-3 text-loss">
					{ errorMessage( remove.error ) }
				</p>
			) }
		</ConfirmModal>
	);
}
