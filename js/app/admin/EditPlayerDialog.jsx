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

// Matches the Gender enum on the backend (src/Entity/Enum/Gender.php). The
// empty option keeps "unset" selectable — but note PUT /players/{id} can only
// *set* these fields, not clear them back to NULL (a null param reads as
// "unchanged"), so leaving a field blank leaves the stored value untouched.
const GENDER_OPTIONS = [
	{ value: '', label: '—' },
	{ value: 'male', label: 'Male' },
	{ value: 'female', label: 'Female' },
	{ value: 'other', label: 'Other' },
];

const fieldInput =
	'w-full rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none';

// Edit the four plain Player fields (name, KNSB id, birth year, gender) via
// PATCH /players/{id}. Email is a Member-account concern, not a Player field, so
// it is intentionally absent here. Only non-empty fields are sent: the update
// endpoint treats a null param as "leave unchanged", so blanks can't clear a
// value this pass — they simply keep what's stored.
export function EditPlayerDialog( { player, onClose } ) {
	const queryClient = useQueryClient();
	const [ name, setName ] = useState( player.name ?? '' );
	const [ knsbId, setKnsbId ] = useState( player.knsb_id ?? '' );
	const [ birthYear, setBirthYear ] = useState(
		player.birth_year != null ? String( player.birth_year ) : ''
	);
	const [ gender, setGender ] = useState( player.gender ?? '' );

	const save = useMutation( {
		mutationFn: ( payload ) =>
			api.patch( `players/${ player.id }`, payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: keys.adminPlayers() } );
			onClose();
		},
	} );

	const trimmedName = name.trim();
	const canSave = trimmedName !== '' && ! save.isPending;

	const submit = ( e ) => {
		e.preventDefault();
		if ( ! canSave ) {
			return;
		}
		const payload = { name: trimmedName };
		if ( knsbId.trim() !== '' ) {
			payload.knsb_id = knsbId.trim();
		}
		if ( birthYear.trim() !== '' ) {
			payload.birth_year = Number( birthYear );
		}
		if ( gender !== '' ) {
			payload.gender = gender;
		}
		save.mutate( payload );
	};

	return (
		<Dialog
			title="Edit player"
			size="sm"
			busy={ save.isPending }
			onClose={ onClose }
			onSubmit={ submit }
		>
			<div className="mt-4 space-y-4">
				<label className="block">
					<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
						Name
					</span>
					<input
						type="text"
						value={ name }
						onChange={ ( e ) => setName( e.target.value ) }
						required
						autoFocus
						className={ fieldInput }
					/>
				</label>

				<label className="block">
					<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
						KNSB ID
					</span>
					<input
						type="text"
						value={ knsbId }
						onChange={ ( e ) => setKnsbId( e.target.value ) }
						placeholder="e.g. 7970886"
						className={ `${ fieldInput } font-mono` }
					/>
				</label>

				<label className="block">
					<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
						Birth year
					</span>
					<input
						type="number"
						inputMode="numeric"
						min="1900"
						max="2100"
						step="1"
						value={ birthYear }
						onChange={ ( e ) => setBirthYear( e.target.value ) }
						placeholder="e.g. 1985"
						className={ `${ fieldInput } font-mono` }
					/>
				</label>

				<label className="block">
					<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
						Gender
					</span>
					<select
						value={ gender }
						onChange={ ( e ) => setGender( e.target.value ) }
						className={ fieldInput }
					>
						{ GENDER_OPTIONS.map( ( o ) => (
							<option key={ o.value } value={ o.value }>
								{ o.label }
							</option>
						) ) }
					</select>
				</label>
			</div>

			{ save.isError && (
				<p className="mt-3 text-sm text-loss">
					{ errorMessage( save.error ) }
				</p>
			) }

			<div className="mt-6 flex justify-end gap-2">
				<button
					type="button"
					className={ ghostBtn }
					onClick={ onClose }
					disabled={ save.isPending }
				>
					Cancel
				</button>
				<button
					type="submit"
					className={ primaryBtn }
					disabled={ ! canSave }
				>
					{ save.isPending ? 'Saving…' : 'Save' }
				</button>
			</div>
		</Dialog>
	);
}
