import { useState, useId, cloneElement } from '@wordpress/element';
import { useForm } from 'react-hook-form';
import { api, ApiError } from '../api/client';

const fieldInput =
	'w-full rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none';
const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';

// User-facing text is authored here, keyed on status, rather than surfacing
// the backend's message (which stays detailed for logging). A 401 from this
// endpoint can only mean the current password didn't match — an invalid
// session is rejected earlier by the permission callback (403).
function errorMessage( err ) {
	if ( err instanceof ApiError && err.status === 401 ) {
		return 'Current password is incorrect.';
	}
	return 'Something went wrong. Please try again.';
}

// Labelled field wrapper: associates the label with the injected control and
// renders an inline validation error beneath it.
function Field( { label, error, children } ) {
	const id = useId();
	return (
		<label className="block" htmlFor={ id }>
			<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
				{ label }
			</span>
			{ cloneElement( children, { id } ) }
			{ error && (
				<span className="mt-1 block text-sm text-loss">{ error }</span>
			) }
		</label>
	);
}

// Modal for changing your own password. Verifies the current password, then
// sets a new one (POST /auth/change-password). On success the server rotates
// this session's cookie and invalidates any others, so nothing else in the app
// needs to react — we just confirm and let the user close.
export function ChangePasswordDialog( { onClose } ) {
	const {
		register,
		handleSubmit,
		watch,
		formState: { errors, isSubmitting },
	} = useForm();
	const [ formError, setFormError ] = useState( null );
	const [ done, setDone ] = useState( false );

	const onSubmit = async ( values ) => {
		setFormError( null );
		try {
			await api.post( 'auth/change-password', {
				current_password: values.currentPassword,
				new_password: values.newPassword,
			} );
			setDone( true );
		} catch ( err ) {
			setFormError( errorMessage( err ) );
		}
	};

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
					Change password
				</h2>

				{ done ? (
					<>
						<p className="mt-4 text-sm text-ink-2">
							Your password has been updated. Any other devices
							signed in to your account have been signed out.
						</p>
						<div className="mt-6 flex justify-end">
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
					<form onSubmit={ handleSubmit( onSubmit ) } noValidate>
						<div className="mt-4 space-y-4">
							<Field
								label="Current password"
								error={ errors.currentPassword?.message }
							>
								<input
									type="password"
									autoComplete="current-password"
									autoFocus
									className={ fieldInput }
									{ ...register( 'currentPassword', {
										required: 'Current password is required.',
									} ) }
								/>
							</Field>

							<Field
								label="New password"
								error={ errors.newPassword?.message }
							>
								<input
									type="password"
									autoComplete="new-password"
									className={ fieldInput }
									{ ...register( 'newPassword', {
										required: 'New password is required.',
										minLength: {
											value: 8,
											message:
												'Use at least 8 characters.',
										},
									} ) }
								/>
							</Field>

							<Field
								label="Confirm new password"
								error={ errors.confirm?.message }
							>
								<input
									type="password"
									autoComplete="new-password"
									className={ fieldInput }
									{ ...register( 'confirm', {
										validate: ( v ) =>
											v === watch( 'newPassword' ) ||
											'New passwords do not match.',
									} ) }
								/>
							</Field>
						</div>

						<p className="mt-4 text-sm text-ink-3">
							A long passphrase of a few random words is stronger and
							easier to remember than a short, complex one. We
							recommend a password manager to generate and store a
							unique password for this site.
						</p>

						{ formError && (
							<p className="mt-3 text-sm text-loss">
								{ formError }
							</p>
						) }

						<div className="mt-6 flex justify-end gap-2">
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
								{ isSubmitting ? 'Saving…' : 'Update password' }
							</button>
						</div>
					</form>
				) }
			</div>
		</div>
	);
}
