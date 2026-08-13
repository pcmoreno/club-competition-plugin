import { useState, useEffect } from '@wordpress/element';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import {
	PAIRING_OPTIONS,
	fieldInput,
	primaryBtn,
	errorMessage,
} from './tournamentShared';
import { TIME_CONTROL_OPTIONS, DEFAULT_TIME_CONTROL } from '../components/game';
import { ContactsField } from './ContactsField';
import { keys } from '../api/keys';

// ADMIN. Basic-details tab of the tournament detail page. Edits the same fields
// as the Create dialog (name, pairing system, dates, time control, location,
// contacts) via PATCH /seasons/{id}. Changing the pairing system is guarded
// server-side (it
// resets pairing settings and is blocked after the first completed round); any
// such error surfaces inline. Blank dates are left untouched — like the player
// edit form, a missing param reads as "unchanged", so this pass can't clear a
// date back to empty.
export function TournamentBasicTab( { season, locked = false } ) {
	const queryClient = useQueryClient();
	const [ name, setName ] = useState( season.name ?? '' );
	const [ pairing, setPairing ] = useState( season.pairing_system );
	const [ startDate, setStartDate ] = useState( season.start_date ?? '' );
	const [ endDate, setEndDate ] = useState( season.end_date ?? '' );
	const [ location, setLocation ] = useState( season.location ?? '' );
	const [ timeControl, setTimeControl ] = useState(
		season.time_control ?? DEFAULT_TIME_CONTROL
	);

	// Contacts live in their own table, so they arrive from their own endpoint
	// rather than on the season payload the parent already holds.
	const { data: contactData } = useQuery( {
		queryKey: keys.seasonContacts( season.id ),
		queryFn: () => api.get( `seasons/${ season.id }/contacts` ),
	} );
	const savedContacts = ( contactData?.contacts ?? [] ).map( ( a ) => a.id );
	const [ contacts, setContacts ] = useState( null );
	// Seed the editable copy once the fetch lands, and re-seed after a save so
	// the form tracks what's stored instead of a stale local edit.
	useEffect( () => {
		if ( contactData ) {
			setContacts( savedContacts );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ contactData ] );
	const contactIds = contacts ?? savedContacts;

	// Fixed once the tournament leaves preparation; pairing *settings* stay editable.
	const fixedOnStart = season.status !== 'preparation';

	const save = useMutation( {
		mutationFn: ( payload ) =>
			api.patch( `seasons/${ season.id }`, payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: keys.season( season.id ),
			} );
			queryClient.invalidateQueries( { queryKey: keys.seasons() } );
			queryClient.invalidateQueries( {
				queryKey: keys.seasonContacts( season.id ),
			} );
		},
	} );

	const trimmedName = name.trim();
	const trimmedLocation = location.trim();
	// Order is meaningful here (it's the order contacts are stored in), so this
	// compares the lists as sequences rather than as sets.
	const contactsDirty =
		contactIds.length !== savedContacts.length ||
		contactIds.some( ( id, i ) => id !== savedContacts[ i ] );
	const dirty =
		trimmedName !== ( season.name ?? '' ) ||
		pairing !== season.pairing_system ||
		startDate !== ( season.start_date ?? '' ) ||
		endDate !== ( season.end_date ?? '' ) ||
		trimmedLocation !== ( season.location ?? '' ) ||
		timeControl !== ( season.time_control ?? DEFAULT_TIME_CONTROL ) ||
		contactsDirty;
	const canSave = trimmedName !== '' && dirty && ! save.isPending;

	const submit = ( e ) => {
		e.preventDefault();
		if ( ! canSave ) {
			return;
		}
		const payload = {
			name: trimmedName,
			pairing_system: pairing,
			time_control: timeControl,
			location: trimmedLocation,
			contact_admin_ids: contactIds,
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
			{ /* One fieldset rather than a disabled per control, so a field added later can't be missed. */ }
			<fieldset disabled={ locked } className="space-y-4">
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
					disabled={ fixedOnStart }
					className={
						fieldInput +
						( fixedOnStart
							? ' cursor-not-allowed opacity-60'
							: '' )
					}
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
				{ fixedOnStart && (
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
						disabled={ fixedOnStart }
						className={
							fieldInput +
							( fixedOnStart
								? ' cursor-not-allowed opacity-60'
								: '' )
						}
					/>
					{ fixedOnStart && (
						<span className="mt-1 block text-xs text-muted">
							The start date is locked once the tournament has
							started.
						</span>
					) }
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
					Time control
				</span>
				<select
					value={ timeControl }
					onChange={ ( e ) => setTimeControl( e.target.value ) }
					disabled={ fixedOnStart }
					className={
						fieldInput +
						( fixedOnStart ? ' cursor-not-allowed opacity-60' : '' )
					}
				>
					{ TIME_CONTROL_OPTIONS.map( ( o ) => (
						<option key={ o.value } value={ o.value }>
							{ o.label }
						</option>
					) ) }
				</select>
				<span className="mt-1 block text-xs text-muted">
					{ fixedOnStart
						? 'The time control is locked once the tournament has started — its games already carry it.'
						: 'Games take the tournament’s time control when they are paired.' }
				</span>
			</label>

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

			<ContactsField value={ contactIds } onChange={ setContacts } />
			</fieldset>

			{ save.isError && (
				<p className="text-sm text-loss">
					{ errorMessage( save.error ) }
				</p>
			) }

			{ ! locked && (
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
			) }
		</form>
	);
}
