import { useState } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { Notice, formatDate } from '../components/ui';
import { ChangePasswordDialog } from '../account/ChangePasswordDialog';
import { keys } from '../api/keys';

const GENDER_LABELS = { male: 'Male', female: 'Female', other: 'Other' };

function titleCase( value ) {
	if ( ! value ) {
		return '—';
	}
	return value.charAt( 0 ).toUpperCase() + value.slice( 1 );
}

// One label/value row in the account details card. Values that are missing
// render as an em dash rather than an empty gap.
function Row( { label, children } ) {
	return (
		<div className="flex flex-col gap-0.5 border-b border-rule py-3 last:border-b-0 sm:flex-row sm:items-baseline sm:gap-4">
			<dt className="text-xs uppercase tracking-wide text-muted sm:w-40 sm:shrink-0">
				{ label }
			</dt>
			<dd className="text-sm text-ink">{ children ?? '—' }</dd>
		</div>
	);
}

// The rows that differ by role. Members are backed by a linked player record
// (name, KNSB details, etc.); admins have only their account fields.
function AccountRows( { data } ) {
	if ( data.role === 'ROLE_ADMIN' ) {
		return (
			<>
				<Row label="Name">{ data.name }</Row>
				<Row label="Email">{ data.email }</Row>
				<Row label="Role">Administrator</Row>
				<Row label="Admin since">
					{ formatDate( data.created_at ) }
				</Row>
			</>
		);
	}

	const player = data.player ?? {};
	return (
		<>
			<Row label="Name">{ player.name }</Row>
			<Row label="Email">{ data.email }</Row>
			<Row label="KNSB ID">{ player.knsb_id }</Row>
			<Row label="KNSB rating">{ player.knsb_elo }</Row>
			<Row label="Birth year">{ player.birth_year }</Row>
			<Row label="Gender">
				{ player.gender ? GENDER_LABELS[ player.gender ] : '—' }
			</Row>
			<Row label="Member since">{ formatDate( data.created_at ) }</Row>
		</>
	);
}

// MEMBER/ADMIN. The signed-in user's own account page (same page for both
// roles, with a shorter body for admins). Read-only detail plus a Security
// section whose only action, for now, is changing the password.
export function Account() {
	const [ showChangePassword, setShowChangePassword ] = useState( false );

	const { data, isLoading, isError } = useQuery( {
		queryKey: keys.account(),
		queryFn: () => api.get( 'auth/me' ),
	} );

	if ( isLoading ) {
		return (
			<Page>
				<Notice>Loading…</Notice>
			</Page>
		);
	}
	if ( isError || ! data ) {
		return (
			<Page>
				<Notice>Could not load your account. Please try again.</Notice>
			</Page>
		);
	}

	return (
		<Page>
			<h1 className="mb-6 font-serif text-[38px] leading-[1.1]">
				Account
			</h1>

			<section className="max-w-2xl rounded border border-rule bg-surface p-6">
				<h2 className="mb-1 font-serif text-xl leading-tight">
					Details
				</h2>
				<dl className="mt-2">
					<AccountRows data={ data } />
				</dl>
			</section>

			<section className="mt-6 max-w-2xl rounded border border-rule bg-surface p-6">
				<h2 className="font-serif text-xl leading-tight">Security</h2>
				<div className="mt-3 flex items-center justify-between gap-4">
					<div>
						<p className="text-sm font-medium text-ink">Password</p>
						<p className="text-sm text-ink-3">
							Change the password you use to sign in.
						</p>
					</div>
					<button
						type="button"
						className="shrink-0 rounded border border-rule px-4 py-2 text-sm font-medium text-ink hover:bg-rule/40"
						onClick={ () => setShowChangePassword( true ) }
					>
						Change password
					</button>
				</div>
			</section>

			{ showChangePassword && (
				<ChangePasswordDialog
					onClose={ () => setShowChangePassword( false ) }
				/>
			) }
		</Page>
	);
}
