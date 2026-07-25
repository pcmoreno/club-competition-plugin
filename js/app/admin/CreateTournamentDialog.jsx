import { useState } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink disabled:opacity-60';
const fieldInput =
	'w-full rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none disabled:opacity-60';

function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		// Field-level validation errors come back keyed by path.
		const errors = err.body?.data?.errors || err.body?.errors;
		if ( errors && typeof errors === 'object' ) {
			return Object.entries( errors )
				.map( ( [ key, msg ] ) => `${ key }: ${ msg }` )
				.join( ' · ' );
		}
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

// PairingSystem enum (src/Entity/Enum/PairingSystem.php). Scoring is derived
// from the pairing system on the backend, so it isn't chosen here. Keizer is
// the club default.
const PAIRING_OPTIONS = [
	{ value: 'keizer', label: 'Keizer' },
	{ value: 'swiss', label: 'Swiss' },
	{ value: 'manual', label: 'Manual' },
	{ value: 'round-robin-full', label: 'Round-robin' },
	{ value: 'round-robin-groups', label: 'Round-robin (groups)' },
];

// ADMIN. Create a tournament (season) via POST /seasons. Phase 1: the basics
// only — name, location, dates, pairing system. The tournament is created in
// Preparation; scoring/display settings and player enrolment are done afterward
// through their own flows. Categories are deferred.
export function CreateTournamentDialog( { onClose } ) {
	const queryClient = useQueryClient();
	const [ name, setName ] = useState( '' );
	const [ location, setLocation ] = useState( '' );
	const [ startDate, setStartDate ] = useState( '' );
	const [ endDate, setEndDate ] = useState( '' );
	const [ pairing, setPairing ] = useState( 'keizer' );

	const create = useMutation( {
		mutationFn: ( payload ) => api.post( 'seasons', payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'seasons' ] } );
			onClose();
		},
	} );

	const trimmedName = name.trim();
	const canSave = trimmedName !== '' && ! create.isPending;

	const submit = ( e ) => {
		e.preventDefault();
		if ( ! canSave ) {
			return;
		}
		const payload = { name: trimmedName, pairing_system: pairing };
		if ( location.trim() !== '' ) {
			payload.location = location.trim();
		}
		if ( startDate !== '' ) {
			payload.start_date = startDate;
		}
		if ( endDate !== '' ) {
			payload.end_date = endDate;
		}
		create.mutate( payload );
	};

	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onClose }
		>
			<form
				className="w-full max-w-md rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
				onSubmit={ submit }
			>
				<h2 className="font-serif text-2xl leading-tight">
					Create tournament
				</h2>
				<p className="mt-1 text-sm text-ink-3">
					Sets up a new tournament in preparation. Scoring settings and
					players are added afterward.
				</p>

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
							placeholder="e.g. Interne competitie 2026/2027"
							className={ fieldInput }
						/>
					</label>

					<label className="block">
						<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
							Pairing system
						</span>
						<select
							value={ pairing }
							onChange={ ( e ) => setPairing( e.target.value ) }
							className={ fieldInput }
						>
							{ PAIRING_OPTIONS.map( ( o ) => (
								<option key={ o.value } value={ o.value }>
									{ o.label }
								</option>
							) ) }
						</select>
					</label>

					<div className="grid grid-cols-2 gap-3">
						<label className="block">
							<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
								Start date
							</span>
							<input
								type="date"
								value={ startDate }
								onChange={ ( e ) =>
									setStartDate( e.target.value )
								}
								className={ fieldInput }
							/>
						</label>
						<label className="block">
							<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
								End date
							</span>
							<input
								type="date"
								value={ endDate }
								onChange={ ( e ) => setEndDate( e.target.value ) }
								className={ fieldInput }
							/>
						</label>
					</div>

					<label className="block">
						<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
							Location
						</span>
						<input
							type="text"
							value={ location }
							onChange={ ( e ) => setLocation( e.target.value ) }
							placeholder="Optional"
							className={ fieldInput }
						/>
					</label>
				</div>

				{ create.isError && (
					<p className="mt-3 text-sm text-loss">
						{ errorMessage( create.error ) }
					</p>
				) }

				<div className="mt-6 flex justify-end gap-2">
					<button
						type="button"
						className={ ghostBtn }
						onClick={ onClose }
						disabled={ create.isPending }
					>
						Cancel
					</button>
					<button
						type="submit"
						className={ primaryBtn }
						disabled={ ! canSave }
					>
						{ create.isPending ? 'Creating…' : 'Create tournament' }
					</button>
				</div>
			</form>
		</div>
	);
}
