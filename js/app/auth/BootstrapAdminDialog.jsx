import { useState } from '@wordpress/element';
import { useForm } from 'react-hook-form';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from './AuthContext';
import { api, ApiError } from '../api/client';
import { Dialog } from '../components/Dialog';
import { navigate } from '../router/router';
import { keys } from '../api/keys';

const inputClass =
	'w-full rounded border-rule bg-surface px-3 py-2 text-ink focus:border-accent focus:ring-accent';
const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:cursor-not-allowed disabled:opacity-50';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';

function errorMessage( err ) {
	// The 409 backstop ("Admin setup is already complete." / duplicate email)
	// carries a curated, user-safe message in body.error; surface it here.
	if ( err instanceof ApiError && err.status === 409 && err.body?.error ) {
		return err.body.error;
	}
	return 'Something went wrong. Please try again.';
}

function Field( { label, error, children } ) {
	return (
		<div>
			<label className="mb-1 block text-sm font-medium text-ink-2">
				{ label }
			</label>
			{ children }
			{ error && (
				<span className="mt-1 block text-sm text-loss">{ error }</span>
			) }
		</div>
	);
}

// PUBLIC, break-glass. Creates the very first admin from the unauthenticated
// page when WP-CLI isn't reachable. Only rendered while the admins table is
// empty (the caller gates on GET /auth/bootstrap-status); the server re-checks
// that invariant, so this is inert once any admin exists. On success it logs
// straight in with the new credentials.
export function BootstrapAdminDialog( { onClose } ) {
	const { login } = useAuth();
	const queryClient = useQueryClient();
	const {
		register,
		handleSubmit,
		getValues,
		formState: { errors, isSubmitting },
	} = useForm();
	const [ formError, setFormError ] = useState( null );

	const onSubmit = async ( { email, password } ) => {
		setFormError( null );
		try {
			await api.post( 'auth/bootstrap-admin', { email, password } );
			// The button must disappear now that an admin exists.
			queryClient.invalidateQueries( {
				queryKey: keys.adminBootstrapStatus(),
			} );
			// Log straight in with the credentials just set, then land in-app.
			try {
				await login( email, password );
				onClose();
				navigate( '/pairings' );
			} catch {
				// Account exists even if the auto-login hiccups — send them to
				// the normal sign-in form to finish.
				onClose();
				navigate( '/login' );
			}
		} catch ( err ) {
			setFormError( errorMessage( err ) );
		}
	};

	return (
		<Dialog
			title="New admin"
			description="Create the first admin account for this site. This is only available while no admin exists yet."
			busy={ isSubmitting }
			onClose={ onClose }
		>
			<form
				onSubmit={ handleSubmit( onSubmit ) }
				noValidate
				className="mt-5 space-y-4"
			>
				<Field label="Email" error={ errors.email?.message }>
					<input
						type="email"
						className={ inputClass }
						autoComplete="email"
						{ ...register( 'email', {
							required: 'Email is required.',
						} ) }
					/>
				</Field>
				<Field label="Password" error={ errors.password?.message }>
					<input
						type="password"
						className={ inputClass }
						autoComplete="new-password"
						{ ...register( 'password', {
							required: 'Password is required.',
							minLength: {
								value: 8,
								message: 'Use at least 8 characters.',
							},
						} ) }
					/>
				</Field>
				<Field
					label="Confirm password"
					error={ errors.confirm?.message }
				>
					<input
						type="password"
						className={ inputClass }
						autoComplete="new-password"
						{ ...register( 'confirm', {
							// deps revalidates this when `password` changes;
							// getValues reads it without subscribing the form to
							// every keystroke. Same pair in AuthScreens and
							// ChangePasswordDialog.
							deps: [ 'password' ],
							validate: ( v ) =>
								v === getValues( 'password' ) ||
								'Passwords do not match.',
						} ) }
					/>
				</Field>

				{ formError && (
					<p className="text-sm text-loss">{ formError }</p>
				) }

				<div className="flex justify-end gap-2 border-t border-rule-soft pt-4">
					<button
						type="button"
						className={ ghostBtn }
						onClick={ onClose }
						disabled={ isSubmitting }
					>
						Cancel
					</button>
					<button
						type="submit"
						className={ primaryBtn }
						disabled={ isSubmitting }
					>
						{ isSubmitting ? 'Creating…' : 'Create admin' }
					</button>
				</div>
			</form>
		</Dialog>
	);
}
