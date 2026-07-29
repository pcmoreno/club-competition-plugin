import { useState } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import {
	PAIRING_OPTIONS,
	fieldInput,
	primaryBtn,
	errorMessage,
} from './tournamentShared';
import { keys } from '../api/keys';

// ADMIN. Basic-details tab of the tournament detail page. Edits the same fields
// as the Create dialog (name, pairing system, dates, location) via
// PATCH /seasons/{id}. Changing the pairing system is guarded server-side (it
// resets pairing settings and is blocked after the first completed round); any
// such error surfaces inline. Blank dates are left untouched — like the player
// edit form, a missing param reads as "unchanged", so this pass can't clear a
// date back to empty.
export function TournamentBasicTab( { season } ) {
	const queryClient = useQueryClient();
	const [ name, setName ] = useState( season.name ?? '' );
	const [ pairing, setPairing ] = useState( season.pairing_system );
	const [ startDate, setStartDate ] = useState( season.start_date ?? '' );
	const [ endDate, setEndDate ] = useState( season.end_date ?? '' );
	const [ location, setLocation ] = useState( season.location ?? '' );

	// The pairing system can only change while the tournament is in preparation;
	// once it's active the games are already keyed to that system. (Pairing
	// *settings* stay editable — those are tuned per round.)
	const pairingLocked = season.status !== 'preparation';

	const save = useMutation( {
		mutationFn: ( payload ) => api.patch( `seasons/${ season.id }`, payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: keys.season( season.id ) } );
			queryClient.invalidateQueries( { queryKey: keys.seasons() } );
		},
	} );

	const trimmedName = name.trim();
	const trimmedLocation = location.trim();
	const dirty =
		trimmedName !== ( season.name ?? '' ) ||
		pairing !== season.pairing_system ||
		startDate !== ( season.start_date ?? '' ) ||
		endDate !== ( season.end_date ?? '' ) ||
		trimmedLocation !== ( season.location ?? '' );
	const canSave = trimmedName !== '' && dirty && ! save.isPending;

	const submit = ( e ) => {
		e.preventDefault();
		if ( ! canSave ) {
			return;
		}
		const payload = {
			name: trimmedName,
			pairing_system: pairing,
			location: trimmedLocation,
		};
		// Dates can't be cleared this pass (empty fails Date validation and a
		// missing param means "unchanged"), so only send them when set.
		if ( startDate !== '' ) {
			payload.start_date = startDate;
		}
		if ( endDate !== '' ) {
			payload.end_date = endDate;
		}
		save.mutate( payload );
	};

	return (
		<form className="space-y-4" onSubmit={ submit }>
			<label className="block">
				<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
					Name
				</span>
				<input
					type="text"
					value={ name }
					onChange={ ( e ) => setName( e.target.value ) }
					required
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
					disabled={ pairingLocked }
					className={ fieldInput + ( pairingLocked ? ' cursor-not-allowed opacity-60' : '' ) }
				>
					{ PAIRING_OPTIONS.map( ( o ) => (
						<option
							key={ o.value }
							value={ o.value }
							// Keep the season's current system selectable even if
							// it's unimplemented, so the select can render it.
							disabled={
								o.implemented === false &&
								o.value !== season.pairing_system
							}
						>
							{ o.label }
							{ o.implemented === false
								? ' (not implemented)'
								: '' }
						</option>
					) ) }
				</select>
				{ pairingLocked && (
					<span className="mt-1 block text-xs text-muted">
						The pairing system is locked once the tournament has
						started.
					</span>
				) }
			</label>

			<div className="grid grid-cols-2 gap-3">
				<label className="block">
					<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
						Start date
					</span>
					<input
						type="date"
						value={ startDate }
						onChange={ ( e ) => setStartDate( e.target.value ) }
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

			{ save.isError && (
				<p className="text-sm text-loss">
					{ errorMessage( save.error ) }
				</p>
			) }

			<div className="flex items-center gap-3 pt-2">
				<button
					type="submit"
					className={ primaryBtn }
					disabled={ ! canSave }
				>
					{ save.isPending ? 'Saving…' : 'Save changes' }
				</button>
				{ save.isSuccess && ! dirty && (
					<span className="text-sm text-muted">Saved.</span>
				) }
			</div>
		</form>
	);
}
