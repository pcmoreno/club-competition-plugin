import { useState } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { formatDate, ConfirmModal } from '../components/ui';
import { keys } from '../api/keys';

const fieldInput =
	'w-full rounded border border-rule bg-surface px-3 py-1.5 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none disabled:opacity-60';
const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';

// The backend's typed exceptions ("This round is closed — talk to the admin.")
// carry a curated message in body.error; anything else gets the generic line.
function errorMessage( err ) {
	if ( err instanceof ApiError && err.body?.error ) {
		return err.body.error;
	}
	return 'Something went wrong. Please try again.';
}

// "Interne competitie 2025/2026 — Round 12 · Tue 9 Dec". Rounds without a date
// are still selectable; they just read as the round number alone.
function optionLabel( entry ) {
	const date = formatDate( entry.round.date );
	return [
		entry.season.name,
		` — Round ${ entry.round.number }`,
		date ? ` · ${ date }` : '',
	].join( '' );
}

// MEMBER. "I can't play this round", under the next-game cards.
//
// One round per submission — miss two evenings, say so twice. What submitting
// does depends on the round: while it's still draft the absence is recorded and
// can be withdrawn here; once pairings are out it only emails the admin, who
// marks it and re-pairs (the app never puts an absent player on a board). Only
// classical tournaments offer it at all — see RoundAbsenceService.
export function AbsenceCard( { declinable } ) {
	const queryClient = useQueryClient();
	const [ roundId, setRoundId ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	// The round just submitted, so the confirmation can name it.
	const [ sent, setSent ] = useState( null );
	// Set while the confirm dialog is open, holding the entry being confirmed.
	const [ confirming, setConfirming ] = useState( null );

	const refresh = () =>
		queryClient.invalidateQueries( { queryKey: keys.home() } );

	const declare = useMutation( {
		mutationFn: ( { id, text } ) =>
			api.post( `me/rounds/${ id }/absence`, { reason: text } ),
		onSuccess: ( result ) => {
			setSent( result );
			setConfirming( null );
			setRoundId( '' );
			setReason( '' );
			refresh();
		},
	} );

	const withdraw = useMutation( {
		mutationFn: ( id ) => api.del( `me/rounds/${ id }/absence` ),
		onSuccess: () => {
			setSent( null );
			refresh();
		},
	} );

	if ( declinable.length === 0 ) {
		return null;
	}

	const open = declinable.filter( ( e ) => ! e.declared );
	const declared = declinable.filter( ( e ) => e.declared );
	const selected = open.find( ( e ) => String( e.round.id ) === roundId );
	const busy = declare.isPending || withdraw.isPending;

	// Submitting opens the confirm rather than firing: saying you'll miss a round
	// reaches the admin either way, so it's worth one deliberate step.
	const submit = ( e ) => {
		e.preventDefault();
		if ( ! selected || busy ) {
			return;
		}
		setSent( null );
		setConfirming( selected );
	};

	return (
		<div className="mt-3 rounded border border-rule bg-surface p-5 shadow-sm">
			<h3 className="font-medium text-ink">Can’t play a round?</h3>
			<p className="mt-1 text-sm text-ink-3">
				Let the club know which evening you’ll miss. One round at a time
				— pick another after this one.
			</p>

			{ declared.length > 0 && (
				<ul className="mt-4 space-y-2">
					{ declared.map( ( entry ) => (
						<li
							key={ entry.round.id }
							className="flex flex-wrap items-baseline justify-between gap-2 rounded border border-rule-soft px-3 py-2 text-sm"
						>
							<span className="text-ink-2">
								You’re down as absent for{ ' ' }
								{ optionLabel( entry ) }
							</span>
							<button
								type="button"
								className="text-sm text-ink-3 underline-offset-2 hover:text-accent hover:underline disabled:opacity-60"
								onClick={ () =>
									withdraw.mutate( entry.round.id )
								}
								disabled={ busy }
							>
								I can play after all
							</button>
						</li>
					) ) }
				</ul>
			) }

			{ open.length > 0 && (
				<form className="mt-4 space-y-3" onSubmit={ submit }>
					<label className="block">
						<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
							Round
						</span>
						<select
							value={ roundId }
							onChange={ ( e ) => {
								setRoundId( e.target.value );
								setSent( null );
							} }
							className={ fieldInput }
							disabled={ busy }
						>
							<option value="">Choose a round…</option>
							{ open.map( ( entry ) => (
								<option
									key={ entry.round.id }
									value={ entry.round.id }
								>
									{ optionLabel( entry ) }
								</option>
							) ) }
						</select>
					</label>

					<label className="block">
						<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
							Reason (optional)
						</span>
						<input
							type="text"
							value={ reason }
							onChange={ ( e ) => setReason( e.target.value ) }
							maxLength={ 500 }
							placeholder="Only the admin sees this"
							className={ fieldInput }
							disabled={ busy }
						/>
					</label>

					{ selected?.mode === 'request' && (
						<p className="text-sm text-ink-3">
							Pairings for this round are already out, so the
							admin has to re-pair the board. Sending this tells
							them — nothing changes until they do.
						</p>
					) }

					{ declare.isError && (
						<p className="text-sm text-loss">
							{ errorMessage( declare.error ) }
						</p>
					) }
					{ withdraw.isError && (
						<p className="text-sm text-loss">
							{ errorMessage( withdraw.error ) }
						</p>
					) }
					{ sent && (
						<p className="text-sm text-win">
							{ sent.declared
								? 'Noted — you’re down as absent for that round.'
								: 'Sent — the admin will confirm. You’ll see it here once they do.' }
						</p>
					) }

					<button
						type="submit"
						className={ primaryBtn }
						disabled={ ! selected || busy }
					>
						{ declare.isPending ? 'Sending…' : 'I can’t play' }
					</button>
				</form>
			) }

			{ confirming && (
				<ConfirmModal
					title="Can’t play this round?"
					confirmLabel="Yes, I can’t play"
					busy={ declare.isPending }
					onCancel={ () => setConfirming( null ) }
					onConfirm={ () =>
						declare.mutate( {
							id: confirming.round.id,
							text: reason.trim(),
						} )
					}
				>
					<p>{ optionLabel( confirming ) }</p>
					<p className="mt-2">
						{ confirming.mode === 'request'
							? 'Pairings are already out, so nothing changes yet — the admin gets an email and will re-pair your board.'
							: 'You’ll be marked absent for this round. It doesn’t score you a bye, and you can undo it here while the pairings are still to come.' }
					</p>
					{ reason.trim() !== '' && (
						<p className="mt-2">
							The admin will see your reason: “{ reason.trim() }”
						</p>
					) }
				</ConfirmModal>
			) }
		</div>
	);
}
