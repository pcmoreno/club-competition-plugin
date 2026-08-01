import { useState } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { Dialog } from '../components/Dialog';
import { keys } from '../api/keys';

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';

function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

const fieldInput =
	'w-full rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none';

// Invite a player to become a member, or re-send a pending invite. Posts to
// POST /players/{id}/invite, which creates a member account (ROLE_MEMBER, not
// admin) and emails a one-time link to set a password — or, when the player is
// already "invited", mints a fresh token and emails it again. Three modes,
// derived from the player's member_status: a first-time invite, a resend for a
// still-pending invite, and a re-invite for a previously revoked account (the
// backend routes all three through the same endpoint). The email is prefilled
// so the admin can also correct a typo.
export function InviteMemberDialog( { player, onClose } ) {
	const queryClient = useQueryClient();
	const isResend = player.member_status === 'invited';
	const isReinvite = player.member_status === 'revoked';
	const [ email, setEmail ] = useState( player.email ?? '' );

	const invite = useMutation( {
		mutationFn: ( payload ) =>
			api.post( `players/${ player.id }/invite`, payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: keys.adminPlayers() } );
			onClose();
		},
	} );

	const canSubmit = email.trim() !== '' && ! invite.isPending;

	const submit = ( e ) => {
		e.preventDefault();
		if ( ! canSubmit ) {
			return;
		}
		invite.mutate( { email: email.trim() } );
	};

	return (
		<Dialog
			title={
				isResend
					? 'Resend invite'
					: isReinvite
					? 'Re-invite member'
					: 'Invite member'
			}
			description={
				isResend ? (
					<>
						<strong className="text-ink">{ player.name }</strong>{ ' ' }
						hasn’t accepted yet. A new email with a fresh link will
						be sent, and the previous link will stop working.
					</>
				) : isReinvite ? (
					<>
						<strong className="text-ink">{ player.name }</strong>
						’s member account was revoked. Re-inviting sends a fresh
						link so they can set a password and sign in again.
					</>
				) : (
					<>
						<strong className="text-ink">{ player.name }</strong>{ ' ' }
						will receive an email with a link to create a password,
						and can then sign in and start using the website. This
						gives them a member account — not admin access.
					</>
				)
			}
			size="sm"
			busy={ invite.isPending }
			onClose={ onClose }
			onSubmit={ submit }
		>
			<label className="mt-4 block">
				<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
					Email
				</span>
				<input
					type="email"
					value={ email }
					onChange={ ( e ) => setEmail( e.target.value ) }
					required
					autoFocus
					placeholder="name@example.com"
					className={ fieldInput }
				/>
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
						: isReinvite
						? 'Re-invite'
						: 'Send invite' }
				</button>
			</div>
		</Dialog>
	);
}
