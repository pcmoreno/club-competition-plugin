import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { Notice } from '../components/ui';

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:cursor-not-allowed disabled:opacity-50';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';

const SEASON_STATUS_LABEL = {
	preparation: 'Preparation',
	active: 'Active',
	completed: 'Completed',
};

// Human labels + styling per member account status. `null` = no login account.
const MEMBER_BADGE = {
	invited: { label: 'Invited', cls: 'bg-ink/10 text-ink-3' },
	active: { label: 'Active', cls: 'bg-win/10 text-win' },
	revoked: { label: 'Revoked', cls: 'bg-loss/10 text-loss' },
};

function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

// ADMIN. Two-step player merge, opened from the roster's Actions menu.
//
// Step 1 (select): pick the player to KEEP and the player to REMOVE from two
// dropdowns of the whole roster. Step 2 (review): both players side by side —
// details + the tournaments each is enrolled in — so the admin can confirm
// they're the same person before committing. The removed player's history moves
// to the keeper (games/standings hang off the season_players pivot, so they
// follow along); the removed player and their member account are deleted.
//
// The merge is refused when both players share a season — repointing would put
// the keeper in it twice. That's detected here (Merge disabled, seasons named)
// and re-checked authoritatively by the backend (409).
export function MergePlayersDialog( { players, onClose } ) {
	const [ step, setStep ] = useState( 'select' );
	const [ keepId, setKeepId ] = useState( '' );
	const [ removeId, setRemoveId ] = useState( '' );

	const byName = [ ...players ].sort( ( a, b ) =>
		( a.name ?? '' ).localeCompare( b.name ?? '' )
	);
	const keep = players.find( ( p ) => String( p.id ) === keepId ) ?? null;
	const remove = players.find( ( p ) => String( p.id ) === removeId ) ?? null;

	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onClose }
		>
			<div
				className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h2 className="font-serif text-2xl leading-tight">
					Merge players
				</h2>

				{ step === 'select' ? (
					<SelectStep
						players={ byName }
						keepId={ keepId }
						removeId={ removeId }
						onKeep={ setKeepId }
						onRemove={ setRemoveId }
						onCancel={ onClose }
						onNext={ () => setStep( 'review' ) }
					/>
				) : (
					<ReviewStep
						keep={ keep }
						remove={ remove }
						onBack={ () => setStep( 'select' ) }
						onDone={ onClose }
					/>
				) }
			</div>
		</div>
	);
}

function SelectStep( {
	players,
	keepId,
	removeId,
	onKeep,
	onRemove,
	onCancel,
	onNext,
} ) {
	const same = keepId !== '' && keepId === removeId;
	const ready = keepId !== '' && removeId !== '' && ! same;

	return (
		<>
			<p className="mt-2 text-sm text-ink-3">
				Everything from the player to remove moves to the player to keep:
				their full competition history, plus any detail the kept player
				is missing (KNSB id, Elo, birth year, gender). Their member
				account moves too, unless the kept player already has one — in
				which case it’s removed.
			</p>

			<div className="mt-5 space-y-4">
				<PlayerSelect
					id="scs-merge-keep"
					label="Player to keep"
					players={ players }
					value={ keepId }
					onChange={ onKeep }
				/>
				<PlayerSelect
					id="scs-merge-remove"
					label="Player to remove"
					players={ players }
					value={ removeId }
					onChange={ onRemove }
				/>
			</div>

			{ same && (
				<p className="mt-3 text-sm text-loss">
					Choose two different players.
				</p>
			) }

			<div className="mt-6 flex justify-end gap-2 border-t border-rule-soft pt-4">
				<button type="button" className={ ghostBtn } onClick={ onCancel }>
					Cancel
				</button>
				<button
					type="button"
					className={ primaryBtn }
					onClick={ onNext }
					disabled={ ! ready }
				>
					Next
				</button>
			</div>
		</>
	);
}

function PlayerSelect( { id, label, players, value, onChange } ) {
	return (
		<div>
			<label
				htmlFor={ id }
				className="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
			>
				{ label }
			</label>
			<select
				id={ id }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				className="w-full rounded border border-rule bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
			>
				<option value="">Select a player…</option>
				{ players.map( ( p ) => (
					<option key={ p.id } value={ String( p.id ) }>
						{ p.name }
					</option>
				) ) }
			</select>
		</div>
	);
}

function ReviewStep( { keep, remove, onBack, onDone } ) {
	const queryClient = useQueryClient();
	const keepQuery = usePlayerTournaments( keep?.id );
	const removeQuery = usePlayerTournaments( remove?.id );

	const merge = useMutation( {
		mutationFn: () =>
			api.post( `players/${ keep.id }/merge`, { source_id: remove.id } ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'admin-players' ] } );
			queryClient.invalidateQueries( {
				queryKey: [ 'player-tournaments', keep.id ],
			} );
			queryClient.removeQueries( {
				queryKey: [ 'player-tournaments', remove.id ],
			} );
			onDone();
		},
	} );

	// A ReviewStep can only mount once both players are selected, but guard
	// anyway so a stale render never dereferences a null.
	if ( ! keep || ! remove ) {
		return null;
	}

	const loading = keepQuery.isLoading || removeQuery.isLoading;
	const failed = keepQuery.isError || removeQuery.isError;
	const bothLoaded =
		Array.isArray( keepQuery.data ) && Array.isArray( removeQuery.data );

	// Seasons both players are enrolled in — the hard blocker.
	const overlap = bothLoaded
		? removeQuery.data.filter( ( rt ) =>
				keepQuery.data.some( ( kt ) => kt.season_id === rt.season_id )
		  )
		: [];

	// Fields the keeper is missing that the removed player fills in — previewed
	// on the keeper's column so the backfill is visible before committing.
	const inherited = inheritedFields( keep, remove );

	// The removed player's account moves to the keeper only when the keeper has
	// none (otherwise the removed one is dropped). Preview it the same way.
	const inheritMember =
		! keep.member_status && remove.member_status
			? remove.member_status
			: null;

	// Different KNSB ids is a soft "are these really the same person?" signal.
	// Only meaningful when both actually carry one (else one just fills the
	// other's blank, above).
	const knsbMismatch =
		Boolean( keep.knsb_id ) &&
		Boolean( remove.knsb_id ) &&
		keep.knsb_id !== remove.knsb_id;

	const canMerge =
		bothLoaded && overlap.length === 0 && ! merge.isPending;

	return (
		<>
			<p className="mt-2 text-sm text-ink-3">
				<strong className="text-ink">{ remove.name }</strong> will be
				merged into{ ' ' }
				<strong className="text-ink">{ keep.name }</strong> and then
				removed. Check these are the same person.
			</p>

			{ loading ? (
				<div className="mt-4">
					<Notice>Loading…</Notice>
				</div>
			) : failed ? (
				<div className="mt-4">
					<Notice>Couldn’t load tournaments. Please try again.</Notice>
				</div>
			) : (
				<div className="mt-4 grid gap-4 sm:grid-cols-2">
					<PlayerColumn
						role="keep"
						player={ keep }
						tournaments={ keepQuery.data }
						inherit={ inherited }
						inheritMember={ inheritMember }
						inheritFrom={ remove.name }
					/>
					<PlayerColumn
						role="remove"
						player={ remove }
						tournaments={ removeQuery.data }
						overlapIds={ overlap.map( ( t ) => t.season_id ) }
					/>
				</div>
			) }

			{ overlap.length > 0 && (
				<p className="mt-4 rounded border border-loss/40 bg-loss/10 px-4 py-3 text-sm text-loss">
					Both players are enrolled in{ ' ' }
					{ overlap.map( ( t ) => t.season_name ).join( ', ' ) }, so
					they can’t be merged — a player can’t appear twice in one
					season.
				</p>
			) }

			{ overlap.length === 0 && knsbMismatch && (
				<p className="mt-4 rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-3">
					These players have different KNSB ids ({ keep.knsb_id } vs{ ' ' }
					{ remove.knsb_id }). Make sure they’re really the same person.
				</p>
			) }

			{ merge.isError && (
				<p className="mt-4 text-sm text-loss">
					{ errorMessage( merge.error ) }
				</p>
			) }

			<div className="mt-6 flex justify-end gap-2 border-t border-rule-soft pt-4">
				<button
					type="button"
					className={ ghostBtn }
					onClick={ onBack }
					disabled={ merge.isPending }
				>
					Cancel
				</button>
				<button
					type="button"
					className={ primaryBtn }
					onClick={ () => merge.mutate() }
					disabled={ ! canMerge }
				>
					{ merge.isPending ? 'Merging…' : 'Merge' }
				</button>
			</div>
		</>
	);
}

// Shares the ['player-tournaments', id] cache with PlayerDetailDialog, so
// opening a player's detail then merging reuses the same fetch.
function usePlayerTournaments( playerId ) {
	return useQuery( {
		queryKey: [ 'player-tournaments', playerId ],
		queryFn: () => api.get( `players/${ playerId }/tournaments` ),
		enabled: playerId != null,
	} );
}

// Fields the keeper is missing that the removed player can fill. Keeper values
// always win; only its blanks are taken. Mirrors PlayerMergeService::backfill.
function inheritedFields( keep, remove ) {
	const out = {};
	for ( const key of [ 'knsb_elo', 'knsb_id', 'birth_year', 'gender' ] ) {
		const own = keep[ key ];
		const isEmpty = own === null || own === undefined || own === '';
		const other = remove[ key ];
		const hasOther = other !== null && other !== undefined && other !== '';
		if ( isEmpty && hasOther ) {
			out[ key ] = other;
		}
	}
	return out;
}

function PlayerColumn( {
	role,
	player,
	tournaments,
	overlapIds = [],
	inherit = {},
	inheritMember = null,
	inheritFrom = null,
} ) {
	const keeping = role === 'keep';

	return (
		<section className="rounded border border-rule bg-surface p-4">
			<div className="flex items-center justify-between gap-2">
				<h3 className="font-serif text-lg leading-tight text-ink">
					{ player.name }
				</h3>
				<span
					className={ [
						'shrink-0 rounded px-2 py-0.5 text-xs font-medium',
						keeping ? 'bg-win/10 text-win' : 'bg-loss/10 text-loss',
					].join( ' ' ) }
				>
					{ keeping ? 'Keeping' : 'Removing' }
				</span>
			</div>

			<dl className="mt-3 space-y-1">
				<Row
					label="KNSB Elo"
					value={ player.knsb_elo }
					mono
					inherited={ inherit.knsb_elo }
					inheritFrom={ inheritFrom }
				/>
				<Row
					label="KNSB ID"
					value={ player.knsb_id }
					mono
					inherited={ inherit.knsb_id }
					inheritFrom={ inheritFrom }
				/>
				<Row
					label="Birth year"
					value={ player.birth_year }
					mono
					inherited={ inherit.birth_year }
					inheritFrom={ inheritFrom }
				/>
				<Row
					label="Gender"
					value={ player.gender }
					inherited={ inherit.gender }
					inheritFrom={ inheritFrom }
				/>
				<MemberRow
					status={ player.member_status }
					inheritStatus={ inheritMember }
					inheritFrom={ inheritFrom }
				/>
			</dl>

			<h4 className="mb-1 mt-4 text-xs font-semibold uppercase tracking-wide text-muted">
				Tournaments
			</h4>
			{ tournaments.length === 0 ? (
				<p className="text-sm text-muted">None.</p>
			) : (
				<ul className="divide-y divide-rule-soft">
					{ tournaments.map( ( t ) => {
						const clash = overlapIds.includes( t.season_id );
						return (
							<li
								key={ t.season_id }
								className="flex items-center justify-between gap-2 py-1.5"
							>
								<span
									className={ [
										'text-sm',
										clash
											? 'font-medium text-loss'
											: 'text-ink',
									].join( ' ' ) }
								>
									{ t.season_name }
								</span>
								<span className="shrink-0 text-xs uppercase tracking-wide text-muted">
									{ SEASON_STATUS_LABEL[ t.season_status ] ??
										t.season_status }
								</span>
							</li>
						);
					} ) }
				</ul>
			) }
		</section>
	);
}

// The Member row renders a status badge rather than a plain value, and its
// backfill is conditional (the account only moves into an empty keeper slot),
// so it gets its own row instead of the generic one.
function MemberRow( { status, inheritStatus = null, inheritFrom = null } ) {
	const badge = status ? MEMBER_BADGE[ status ] : null;
	const inheritBadge =
		! status && inheritStatus ? MEMBER_BADGE[ inheritStatus ] : null;
	const badgeClass = ( cls ) =>
		`rounded px-2 py-0.5 text-xs font-medium ${ cls }`;

	return (
		<div className="flex items-baseline justify-between gap-3">
			<dt className="text-sm text-muted">Member</dt>
			<dd className="text-right text-sm">
				{ badge ? (
					<span className={ badgeClass( badge.cls ) }>
						{ badge.label }
					</span>
				) : inheritBadge ? (
					<>
						<span className={ badgeClass( inheritBadge.cls ) }>
							{ inheritBadge.label }
						</span>
						<span className="ml-1 text-xs text-muted">
							from { inheritFrom }
						</span>
					</>
				) : (
					<span className="text-ink">—</span>
				) }
			</dd>
		</div>
	);
}

function Row( { label, value, mono = false, inherited = null, inheritFrom = null } ) {
	const empty = value === null || value === undefined || value === '';
	const willInherit =
		empty && inherited !== null && inherited !== undefined && inherited !== '';

	return (
		<div className="flex items-baseline justify-between gap-3">
			<dt className="text-sm text-muted">{ label }</dt>
			{ willInherit ? (
				<dd className="text-right text-sm text-accent">
					<span className={ mono ? 'num font-mono' : '' }>
						{ inherited }
					</span>
					<span className="ml-1 text-xs text-muted">
						from { inheritFrom }
					</span>
				</dd>
			) : (
				<dd
					className={ [
						'text-right text-sm text-ink',
						mono ? 'num font-mono' : '',
					].join( ' ' ) }
				>
					{ empty ? '—' : value }
				</dd>
			) }
		</div>
	);
}
