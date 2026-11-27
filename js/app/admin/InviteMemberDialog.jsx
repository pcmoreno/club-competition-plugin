import { useState, useId } from '@wordpress/element';
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

// Title and button per member_status; any other status is a first invite.
const MODES = {
	invited: { title: 'Resend invite', action: 'Resend invite' },
	revoked: { title: 'Re-invite member', action: 'Re-invite' },
};
const FIRST_INVITE = { title: 'Invite member', action: 'Send invite' };

function InviteDescription( { player } ) {
	const name = <strong className="text-ink">{ player.name }</strong>;
	if ( player.member_status === 'invited' ) {
		return (
			<>
				{ name } hasn’t accepted yet. A new email with a fresh link will
				be sent, and the previous link will stop working.
			</>
		);
	}
	if ( player.member_status === 'revoked' ) {
		return (
			<>
				{ name }’s member account was revoked. Re-inviting sends a fresh
				link so they can set a password and sign in again.
			</>
		);
	}
	return (
		<>
			{ name } will receive an email with a link to create a password, and
			can then sign in and start using the website. This gives them a
			member account — not admin access.
		</>
	);
}

// Invite a player to become a member, or re-send a pending invite. Posts to
// POST /players/{id}/invite, which creates a member account (ROLE_MEMBER, not
// admin) and emails a one-time link to set a password — or, when the player is
// already "invited", mints a fresh token and emails it again. Three modes,
// derived from the player's member_status: a first-time invite, a resend for a
// still-pending invite, and a re-invite for a previously revoked account (the
// backend routes all three through the same endpoint). The email is prefilled
// so the admin can also correct a typo.
export function InviteMemberDialog( { player, onClose } ) {
	const fieldId = useId();
	const queryClient = useQueryClient();
	const mode = MODES[ player.member_status ] ?? FIRST_INVITE;
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
			title={ mode.title }
			description={ <InviteDescription player={ player } /> }
			size="sm"
			busy={ invite.isPending }
			onClose={ onClose }
			onSubmit={ submit }
		>
			<label htmlFor={ `${ fieldId }-email` } className="mt-4 block">
				<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
					Email
				</span>
				<input
					id={ `${ fieldId }-email` }
					type="email"
					value={ email }
					onChange={ ( e ) => setEmail( e.target.value ) }
					required
					// eslint-disable-next-line jsx-a11y/no-autofocus -- focus moves into a dialog on open.
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
					{ invite.isPending ? 'Sending…' : mode.action }
				</button>
			</div>
		</Dialog>
	);
}
