import { useState, useId, cloneElement } from '@wordpress/element';
import { useForm } from 'react-hook-form';
import { useAuth } from './AuthContext';
import { api, ApiError } from '../api/client';
import { navigate, getQueryParam, Link } from '../router/router';
import { Page } from '../layout/Page';

// Shared card chrome for the auth forms.
function AuthCard( { title, intro, children } ) {
	return (
		<Page>
			<div className="mx-auto max-w-md">
				<h1 className="mb-1 font-serif text-[28px] leading-[1.15]">
					{ title }
				</h1>
				{ intro && <p className="mb-5 text-ink-3">{ intro }</p> }
				<div className="rounded border border-rule bg-surface p-6 shadow-sm">
					{ children }
				</div>
			</div>
		</Page>
	);
}

function Field( { label, error, children } ) {
	// Associate the label with its control by id (the control is passed as
	// `children`, so inject the generated id onto it).
	const id = useId();
	return (
		<div className="mb-4">
			<label
				htmlFor={ id }
				className="mb-1.5 block text-sm font-medium text-ink-2"
			>
				{ label }
			</label>
			{ cloneElement( children, { id } ) }
			{ error && (
				<span className="mt-1 block text-sm text-loss">{ error }</span>
			) }
		</div>
	);
}

const inputClass =
	'w-full rounded border-rule bg-surface px-3 py-2 text-ink focus:border-accent focus:ring-accent';
const primaryBtn =
	'w-full rounded bg-ink px-4 py-2.5 font-medium text-paper hover:bg-ink-2 disabled:opacity-60';

// Turns an ApiError/Error into a user-facing string.
function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

export function Login() {
	const { login } = useAuth();
	const {
		register,
		handleSubmit,
		formState: { errors, isSubmitting },
	} = useForm();
	const [ formError, setFormError ] = useState( null );

	const onSubmit = async ( { email, password } ) => {
		setFormError( null );
		try {
			await login( email, password );
			navigate( '/pairings' );
		} catch ( err ) {
			setFormError(
				err instanceof ApiError && err.status === 401
					? 'Incorrect email or password.'
					: errorMessage( err )
			);
		}
	};

	return (
		<AuthCard title="Sign in">
			<form onSubmit={ handleSubmit( onSubmit ) } noValidate>
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
						autoComplete="current-password"
						{ ...register( 'password', {
							required: 'Password is required.',
						} ) }
					/>
				</Field>
				{ formError && (
					<p className="mb-4 text-sm text-loss">{ formError }</p>
				) }
				<button
					type="submit"
					className={ primaryBtn }
					disabled={ isSubmitting }
				>
					{ isSubmitting ? 'Signing in…' : 'Sign in' }
				</button>
			</form>
			<p className="mt-4 text-sm text-ink-3">
				<Link to="/forgot-password">Forgot your password?</Link>
			</p>
		</AuthCard>
	);
}

export function ForgotPassword() {
	const {
		register,
		handleSubmit,
		formState: { errors, isSubmitting },
	} = useForm();
	const [ done, setDone ] = useState( false );

	const onSubmit = async ( { email } ) => {
		// Endpoint always returns success (no email enumeration), so we don't
		// branch on the response.
		await api.post( 'auth/forgot-password', { email } ).catch( () => {} );
		setDone( true );
	};

	if ( done ) {
		return (
			<AuthCard
				title="Check your email"
				intro="If that email is registered, a reset link is on its way."
			>
				<Link to="/login">← Back to sign in</Link>
			</AuthCard>
		);
	}

	return (
		<AuthCard
			title="Reset your password"
			intro="Enter your email and we'll send a reset link."
		>
			<form onSubmit={ handleSubmit( onSubmit ) } noValidate>
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
				<button
					type="submit"
					className={ primaryBtn }
					disabled={ isSubmitting }
				>
					{ isSubmitting ? 'Sending…' : 'Send reset link' }
				</button>
			</form>
		</AuthCard>
	);
}

// Shared password-set form for both reset-password and accept-invite — same
// shape (token + new password), different endpoint and copy.
function SetPasswordForm( { title, intro, endpoint, successText } ) {
	const token = getQueryParam( 'token' );
	const {
		register,
		handleSubmit,
		watch,
		formState: { errors, isSubmitting },
	} = useForm();
	const [ formError, setFormError ] = useState( null );
	const [ done, setDone ] = useState( false );

	if ( ! token ) {
		return (
			<AuthCard
				title={ title }
				intro="This link is missing its token. Please use the link from your email."
			>
				<Link to="/login">← Back to sign in</Link>
			</AuthCard>
		);
	}

	if ( done ) {
		return (
			<AuthCard title={ title } intro={ successText }>
				<button
					type="button"
					className={ primaryBtn }
					onClick={ () => navigate( '/login' ) }
				>
					Go to sign in
				</button>
			</AuthCard>
		);
	}

	const onSubmit = async ( { password } ) => {
		setFormError( null );
		try {
			await api.post( endpoint, { token, password } );
			setDone( true );
		} catch ( err ) {
			setFormError( errorMessage( err ) );
		}
	};

	return (
		<AuthCard title={ title } intro={ intro }>
			<form onSubmit={ handleSubmit( onSubmit ) } noValidate>
				<Field label="New password" error={ errors.password?.message }>
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
							validate: ( v ) =>
								v === watch( 'password' ) ||
								'Passwords do not match.',
						} ) }
					/>
				</Field>
				{ formError && (
					<p className="mb-4 text-sm text-loss">{ formError }</p>
				) }
				<button
					type="submit"
					className={ primaryBtn }
					disabled={ isSubmitting }
				>
					{ isSubmitting ? 'Saving…' : 'Set password' }
				</button>
			</form>
		</AuthCard>
	);
}

export function ResetPassword() {
	return (
		<SetPasswordForm
			title="Set a new password"
			intro="Choose a new password for your account."
			endpoint="auth/reset-password"
			successText="Password updated. You can now sign in."
		/>
	);
}

export function AcceptInvite() {
	return (
		<SetPasswordForm
			title="Activate your account"
			intro="Set a password to finish creating your member account."
			endpoint="auth/accept-invite"
			successText="Account activated. You can now sign in."
		/>
	);
}
