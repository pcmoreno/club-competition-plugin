import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { fieldInput } from './tournamentShared';
import { keys } from '../api/keys';

// ADMIN. The tournament-contacts picker, shared by the Create dialog and the
// Basic details tab so both edit the list the same way.
//
// Selection is held by the parent as an array of admin ids (`value`), because
// both callers submit it alongside their other fields in one save. The chosen
// admins render as rows with a remove button, and the select below offers only
// the admins not already on the list.
//
// The list can legitimately be emptied: no contacts means the tournament's
// notifications go to every active admin, which is what the club had before
// contacts existed. The hint below says so, so an empty list doesn't read as
// "nobody will be told".
export function ContactsField( { value, onChange, hint } ) {
	const { data: admins = [], isLoading } = useQuery( {
		queryKey: keys.admins(),
		queryFn: () => api.get( 'admins' ),
	} );

	const byId = new Map( admins.map( ( a ) => [ a.id, a ] ) );
	// Ids the parent holds that no active admin matches (a revoked account, say)
	// are dropped from the display rather than rendered as a blank row.
	const selected = value
		.map( ( id ) => byId.get( id ) )
		.filter( Boolean );
	const available = admins.filter( ( a ) => ! value.includes( a.id ) );

	const add = ( e ) => {
		const id = Number( e.target.value );
		if ( id ) {
			onChange( [ ...value, id ] );
		}
		// Back to the placeholder, so the same admin can't look "stuck" in the
		// select after being added to the list above.
		e.target.value = '';
	};

	const remove = ( id ) => onChange( value.filter( ( v ) => v !== id ) );

	return (
		<div className="block">
			<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
				Contacts
			</span>

			{ selected.length > 0 && (
				<ul className="mb-2 space-y-1">
					{ selected.map( ( admin ) => (
						<li
							key={ admin.id }
							className="flex items-center justify-between gap-3 rounded border border-rule bg-surface px-3 py-1.5"
						>
							<span className="text-sm text-ink">
								{ admin.name }
								<span className="ml-2 text-xs text-muted">
									{ admin.email }
								</span>
							</span>
							<button
								type="button"
								onClick={ () => remove( admin.id ) }
								className="text-xs text-ink-3 hover:text-loss"
								aria-label={ `Remove ${ admin.name } from contacts` }
							>
								Remove
							</button>
						</li>
					) ) }
				</ul>
			) }

			{ available.length > 0 && (
				<select
					value=""
					onChange={ add }
					disabled={ isLoading }
					className={ fieldInput }
					aria-label="Add a contact"
				>
					<option value="">
						{ selected.length > 0
							? 'Add another contact…'
							: 'Add a contact…' }
					</option>
					{ available.map( ( admin ) => (
						<option key={ admin.id } value={ admin.id }>
							{ admin.name } ({ admin.email })
						</option>
					) ) }
				</select>
			) }

			<span className="mt-1 block text-xs text-muted">
				{ hint ??
					( selected.length === 0
						? 'With no contacts, notifications about this tournament go to every admin.'
						: 'Notifications about this tournament go to these admins.' ) }
			</span>
		</div>
	);
}
