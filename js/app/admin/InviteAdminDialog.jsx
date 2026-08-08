import { useState } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { Dialog } from '../components/Dialog';
import { keys } from '../api/keys';

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink disabled:opacity-60';
const fieldInput =
	'w-full rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none';

function errorMessage( err ) {
	// The typed exceptions (403/404/409) carry a curated message in body.error —
	// "An admin with email … already exists", "Only the first admin account can
	// manage admins". Surface it; the shared ApiError layer only reads message.
	if ( err instanceof ApiError && err.body?.error ) {
		return err.body.error;
	}
	return 'Something went wrong. Please try again.';
}

// ADMIN. Invite someone to become an admin (POST /admins), or re-send an invite
// that hasn't been accepted (POST /admins/{id}/invite, which mints a fresh token
// and lets the email be corrected on the way).
//
// Only the first admin account can do either — the backend enforces it, and the
// caller only offers this dialog to that account.
export function InviteAdminDialog( { admin = null, onClose } ) {
	const queryClient = useQueryClient();
	const isResend = admin !== null;
	const [ name, setName ] = useState( admin?.name ?? '' );
	const [ email, setEmail ] = useState( admin?.email ?? '' );

	const invite = useMutation( {
		mutationFn: ( payload ) =>
			isResend
				? api.post( `admins/${ admin.id }/invite`, payload )
				: api.post( 'admins', payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: keys.admins() } );
			onClose();
		},
	} );

	const canSubmit =
		email.trim() !== '' &&
		( isResend || name.trim() !== '' ) &&
		! invite.isPending;

	const submit = ( e ) => {
		e.preventDefault();
		if ( ! canSubmit ) {
			return;
		}
		invite.mutate(
			isResend
				? { email: email.trim() }
				: { name: name.trim(), email: email.trim() }
		);
	};

	return (
		<Dialog
			title={ isResend ? 'Resend admin invite' : 'Invite admin' }
			description={
				isResend ? (
					<>
						<strong className="text-ink">{ admin.name }</strong>{ ' ' }
						hasn’t accepted yet. A new email with a fresh link will be
						sent, and the previous link will stop working.
					</>
				) : (
					<>
						They’ll receive an email with a link to set a password.
						Once they do, they can manage tournaments, players,
						pairings and results — everything you can, except
						inviting further admins.
					</>
				)
			}
			size="sm"
			busy={ invite.isPending }
			onClose={ onClose }
			onSubmit={ submit }
		>
			{ ! isResend && (
				<label className="mt-4 block">
					<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
						Name
					</span>
					<input
						type="text"
						value={ name }
						onChange={ ( e ) => setName( e.target.value ) }
						required
						autoFocus
						placeholder="Jane Doe"
						className={ fieldInput }
					/>
				</label>
			) }

			<label className="mt-4 block">
				<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
					Email
				</span>
				<input
					type="email"
					value={ email }
					onChange={ ( e ) => setEmail( e.target.value ) }
					required
					autoFocus={ isResend }
					placeholder="name@example.com"
					className={ fieldInput }
				/>
				{ /* Member and admin accounts are separate logins, and a shared
				     address always resolves to the member one — so the backend
				     refuses it. Say so before they submit. */ }
				<span className="mt-1 block text-xs text-muted">
					Must be an address that isn’t already used to sign in as a
					member — admin accounts are separate logins.
				</span>
			</label>

			{ invite.isError && (
				<p className="mt-3 text-sm text-loss">
					{ errorMessage( invite.error ) }
				</p>
			) }

			<div className="mt-6 flex justify-end gap-2">
				<button
					type="button"
					className={ ghostBtn }
					onClick={ onClose }
					disabled={ invite.isPending }
				>
					Cancel
				</button>
				<button
					type="submit"
					className={ primaryBtn }
					disabled={ ! canSubmit }
				>
					{ invite.isPending
						? 'Sending…'
						: isResend
						? 'Resend invite'
						: 'Send invite' }
				</button>
			</div>
		</Dialog>
	);
}
