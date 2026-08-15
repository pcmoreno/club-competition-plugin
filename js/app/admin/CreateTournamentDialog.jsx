import { useState, useEffect } from '@wordpress/element';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { Dialog } from '../components/Dialog';
import {
	PAIRING_OPTIONS,
	DEFAULT_PAIRING_SYSTEM,
	fieldInput,
	primaryBtn,
	ghostBtn,
	errorMessage,
} from './tournamentShared';
import { TIME_CONTROL_OPTIONS, DEFAULT_TIME_CONTROL } from '../components/game';
import { ContactsField } from './ContactsField';
import { keys } from '../api/keys';

// ADMIN. Create a tournament (season) via POST /seasons. Phase 1: the basics
// only — name, pairing system, dates, time control, location, contacts. The
// tournament is created in Preparation; scoring/display settings and player
// enrolment are done afterward through their own flows. Categories are deferred.
export function CreateTournamentDialog( { onClose } ) {
	const queryClient = useQueryClient();
	const [ name, setName ] = useState( '' );
	const [ location, setLocation ] = useState( '' );
	const [ startDate, setStartDate ] = useState( '' );
	const [ endDate, setEndDate ] = useState( '' );
	const [ pairing, setPairing ] = useState( DEFAULT_PAIRING_SYSTEM );
	const [ timeControl, setTimeControl ] = useState( DEFAULT_TIME_CONTROL );
	const [ isTeam, setIsTeam ] = useState( false );

	// Contacts default to whoever is creating the tournament. Pre-selected here
	// rather than added server-side after the fact, so the list on screen is the
	// list that gets saved — including when they take themselves back off it.
	const { data: account } = useQuery( {
		queryKey: keys.account(),
		queryFn: () => api.get( 'auth/me' ),
	} );
	const [ contacts, setContacts ] = useState( null );
	useEffect( () => {
		if ( account?.id ) {
			setContacts( ( current ) => current ?? [ account.id ] );
		}
	}, [ account ] );
	const contactIds = contacts ?? [];

	const create = useMutation( {
		mutationFn: ( payload ) => api.post( 'seasons', payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: keys.seasons() } );
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
		const payload = {
			name: trimmedName,
			pairing_system: pairing,
			time_control: timeControl,
			is_team: isTeam,
			contact_admin_ids: contactIds,
		};
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
		<Dialog
			title="Create tournament"
			description="Sets up a new tournament in preparation. Scoring settings and players are added afterward."
			busy={ create.isPending }
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
							<option
								key={ o.value }
								value={ o.value }
								disabled={ o.implemented === false }
							>
								{ o.label }
								{ o.implemented === false
									? ' (not implemented)'
									: '' }
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
						Time control
					</span>
					<select
						value={ timeControl }
						onChange={ ( e ) => setTimeControl( e.target.value ) }
						className={ fieldInput }
					>
						{ TIME_CONTROL_OPTIONS.map( ( o ) => (
							<option key={ o.value } value={ o.value }>
								{ o.label }
							</option>
						) ) }
					</select>
				</label>

				<label className="block">
					<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
						Competition type
					</span>
					<select
						value={ isTeam ? 'team' : 'individual' }
						onChange={ ( e ) =>
							setIsTeam( e.target.value === 'team' )
						}
						className={ fieldInput }
					>
						<option value="individual">Individual</option>
						{ /* Teams can be built, but nothing pairs one against
						     another yet. Mirrors the server, which refuses it. */ }
						<option value="team" disabled>
							Team (not implemented)
						</option>
					</select>
					<span className="mt-1 block text-xs text-muted">
						Team play can be set up but not yet played: no pairing
						system puts one team against another.
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
		</Dialog>
	);
}
