import { useState, useMemo, useRef } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { ConfirmModal } from '../components/ui';
import { TransferPanel } from '../components/TransferPanel';
import { errorMessage } from './tournamentShared';
import { keys } from '../api/keys';

// Each side sorts by the rating it shows: the enrolment rating on the left, the
// player's own KNSB rating on the right.
const enrolledRating = ( p ) => p.elo;
const availableRating = ( p ) => p.knsb_elo;

// The enrolment rating, editable in place: click it, type, Enter or click away
// to save, Escape to cancel. Clicks stop here so they don't toggle the row.
function RatingCell( { value, editable, onSave } ) {
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	// Set while a save runs or after Escape, so the blur that follows doesn't save.
	const settling = useRef( false );

	if ( ! editable ) {
		return value || '—';
	}

	if ( draft === null ) {
		return (
			<button
				type="button"
				title="Adjust rating"
				onClick={ ( e ) => {
					e.stopPropagation();
					settling.current = false;
					setError( null );
					setDraft( String( value ?? '' ) );
				} }
				className="hover:text-ink hover:underline"
			>
				{ value || '—' }
			</button>
		);
	}

	const save = async () => {
		if ( settling.current ) {
			return;
		}
		const rating = Number( draft );
		if (
			draft.trim() === '' ||
			! Number.isInteger( rating ) ||
			rating < 0
		) {
			setError( 'A whole number, zero or more.' );
			return;
		}
		settling.current = true;
		if ( rating === value ) {
			setDraft( null );
			return;
		}
		setSaving( true );
		try {
			await onSave( rating );
			setDraft( null );
		} catch ( err ) {
			settling.current = false;
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	};

	return (
		<input
			type="number"
			// No min: a min would make the arrows snap to multiples of 50 from 0.
			step="50"
			value={ draft }
			disabled={ saving }
			// eslint-disable-next-line jsx-a11y/no-autofocus -- the click that opens the editor asks for it.
			autoFocus
			title={ error ?? undefined }
			aria-invalid={ error ? true : undefined }
			onClick={ ( e ) => e.stopPropagation() }
			onChange={ ( e ) => {
				setError( null );
				setDraft( e.target.value );
			} }
			onKeyDown={ ( e ) => {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					save();
				} else if ( e.key === 'ArrowUp' || e.key === 'ArrowDown' ) {
					e.preventDefault();
					const delta = e.key === 'ArrowUp' ? 50 : -50;
					setError( null );
					setDraft(
						String(
							Math.max( 0, ( Number( draft ) || 0 ) + delta )
						)
					);
				} else if ( e.key === 'Escape' ) {
					e.stopPropagation();
					settling.current = true;
					setDraft( null );
				}
			} }
			onBlur={ save }
			className={
				'w-20 rounded border bg-paper px-1 py-0 text-right font-mono text-xs text-ink focus:outline-none ' +
				( error ? 'border-loss' : 'border-accent' )
			}
		/>
	);
}

// ADMIN. Players (enrolment) tab, as a two-panel transfer list: Enrolled on the
// left, the remaining active club players on the right. Move players by
// dragging between panels or by multi-selecting and using the centre button
// (Add ← / Remove →). Enrolment ignores categories (they're assigned elsewhere);
// ratings auto-fill from the player's KNSB Elo.
export function TournamentPlayersTab( { season, players, locked = false } ) {
	const queryClient = useQueryClient();

	const [ selEnrolled, setSelEnrolled ] = useState( () => new Set() );
	const [ selAvailable, setSelAvailable ] = useState( () => new Set() );
	const [ enrolledSearch, setEnrolledSearch ] = useState( '' );
	const [ availableSearch, setAvailableSearch ] = useState( '' );
	const [ dropTarget, setDropTarget ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const drag = useRef( null );

	const { data: allPlayers } = useQuery( {
		queryKey: keys.adminPlayers(),
		queryFn: () => api.get( 'players' ),
	} );

	const enrolledIds = useMemo(
		() => new Set( players.map( ( p ) => p.player_id ) ),
		[ players ]
	);

	const enrolled = useMemo( () => {
		const q = enrolledSearch.trim().toLowerCase();
		return [ ...players ]
			.filter(
				( p ) => ! q || ( p.name ?? '' ).toLowerCase().includes( q )
			)
			.sort( ( a, b ) => ( a.name ?? '' ).localeCompare( b.name ?? '' ) );
	}, [ players, enrolledSearch ] );

	// Only active (non-retired) club players are enrollable — GET /players
	// returns everyone, so filter here (in both the visible list and "Add all").
	const available = useMemo( () => {
		const list = Array.isArray( allPlayers ) ? allPlayers : [];
		const q = availableSearch.trim().toLowerCase();
		return list
			.filter( ( p ) => p.active )
			.filter( ( p ) => ! enrolledIds.has( p.id ) )
			.filter(
				( p ) => ! q || ( p.name ?? '' ).toLowerCase().includes( q )
			)
			.sort( ( a, b ) => ( a.name ?? '' ).localeCompare( b.name ?? '' ) );
	}, [ allPlayers, enrolledIds, availableSearch ] );

	// Every enrollable / enrolled id, ignoring the filters — the bulk buttons
	// act on all active players, not just the currently visible ones.
	const addAllIds = useMemo(
		() =>
			( Array.isArray( allPlayers ) ? allPlayers : [] )
				.filter( ( p ) => p.active )
				.filter( ( p ) => ! enrolledIds.has( p.id ) )
				.map( ( p ) => p.id ),
		[ allPlayers, enrolledIds ]
	);

	const invalidate = () => {
		queryClient.invalidateQueries( { queryKey: keys.season( season.id ) } );
		queryClient.invalidateQueries( { queryKey: keys.seasons() } );
	};

	// Enrol/remove go through the atomic bulk endpoints — one request for the
	// whole selection, so a 50-player "Add all" doesn't fan out 50 POSTs and a
	// partial failure can't leave the season half-populated.
	const enroll = useMutation( {
		mutationFn: ( { ids } ) =>
			api.post( `seasons/${ season.id }/players/bulk`, {
				player_ids: ids,
			} ),
		onSuccess: () => setSelAvailable( new Set() ),
		onSettled: invalidate,
	} );

	const remove = useMutation( {
		mutationFn: ( { ids } ) =>
			api.del( `seasons/${ season.id }/players/bulk`, {
				body: { player_ids: ids },
			} ),
		onSuccess: () => setSelEnrolled( new Set() ),
		onSettled: invalidate,
	} );

	const rate = useMutation( {
		mutationFn: ( { playerId, rating } ) =>
			api.patch( `seasons/${ season.id }/players/${ playerId }/rating`, {
				elo_rating: rating,
			} ),
		onSettled: invalidate,
	} );

	// The enrolment rating seeds pairing from round 1, so it's fixed once started.
	const canRate = ! locked && season.status === 'preparation';

	const busy = enroll.isPending || remove.isPending;

	// Removing a player who has played orphans their games/attendance/snapshots,
	// so removal is only allowed while the tournament is still in preparation
	// (the server enforces the same rule).
	const canRemove = ! locked && season.status === 'preparation';

	const doEnroll = ( ids ) => {
		if ( ! locked && ids.length > 0 ) {
			enroll.mutate( { ids } );
		}
	};

	const doRemove = ( ids ) => {
		if ( canRemove && ids.length > 0 ) {
			remove.mutate( { ids } );
		}
	};

	const confirmAction = () => {
		if ( confirm === 'add' ) {
			doEnroll( addAllIds );
		} else if ( confirm === 'remove' ) {
			doRemove( players.map( ( p ) => p.player_id ) );
		}
		setConfirm( null );
	};

	// Selecting in one panel clears the other, so the centre button is
	// unambiguous (add from the right, or remove from the left).
	const toggle = ( side, id ) => {
		if ( side === 'available' ) {
			setSelEnrolled( new Set() );
			setSelAvailable( ( prev ) => {
				const next = new Set( prev );
				if ( next.has( id ) ) {
					next.delete( id );
				} else {
					next.add( id );
				}
				return next;
			} );
		} else {
			setSelAvailable( new Set() );
			setSelEnrolled( ( prev ) => {
				const next = new Set( prev );
				if ( next.has( id ) ) {
					next.delete( id );
				} else {
					next.add( id );
				}
				return next;
			} );
		}
	};

	// Shift-click: select or deselect a run of rows in this panel.
	const selectRange = ( side, ids, select ) => {
		const apply = ( prev ) => {
			const next = new Set( prev );
			for ( const id of ids ) {
				if ( select ) {
					next.add( id );
				} else {
					next.delete( id );
				}
			}
			return next;
		};
		if ( side === 'available' ) {
			setSelEnrolled( new Set() );
			setSelAvailable( apply );
		} else {
			setSelAvailable( new Set() );
			setSelEnrolled( apply );
		}
	};

	// Dragging a selected row carries the whole selection; an unselected row
	// carries just itself.
	const onDragStart = ( side, id ) => {
		const sel = side === 'available' ? selAvailable : selEnrolled;
		const ids = sel.has( id ) && sel.size > 0 ? [ ...sel ] : [ id ];
		drag.current = { from: side, ids };
	};

	const onDropTo = ( target ) => {
		const d = drag.current;
		drag.current = null;
		setDropTarget( null );
		if ( ! d ) {
			return;
		}
		if ( target === 'enrolled' && d.from === 'available' ) {
			doEnroll( d.ids );
		} else if ( target === 'available' && d.from === 'enrolled' ) {
			doRemove( d.ids );
		}
	};

	const addMode = selAvailable.size > 0;
	const removeMode = ! addMode && selEnrolled.size > 0;
	const err = enroll.error || remove.error;

	return (
		<div className="space-y-3">
			{ /* Nothing left to enrol, so this collapses to the roster it ended with. */ }
			<div
				className={
					'items-stretch gap-3 ' +
					( locked ? 'max-w-md' : 'grid grid-cols-[1fr_auto_1fr]' )
				}
			>
				<TransferPanel
					title={ `Enrolled (${ players.length })` }
					side="enrolled"
					rows={ enrolled }
					rowId={ ( p ) => p.player_id }
					rowLabel={ ( p ) => p.name }
					rowMeta={ ( p ) => (
						<>
							<RatingCell
								value={ p.elo }
								editable={ canRate }
								onSave={ ( rating ) =>
									rate.mutateAsync( {
										playerId: p.player_id,
										rating,
									} )
								}
							/>
							{ p.category && ` (${ p.category })` }
						</>
					) }
					rowRating={ enrolledRating }
					selected={ selEnrolled }
					search={ enrolledSearch }
					onSearch={ setEnrolledSearch }
					onToggle={ toggle }
					onSelectRange={ selectRange }
					onDragStart={ onDragStart }
					isOver={ dropTarget === 'enrolled' }
					onDragOver={ () => setDropTarget( 'enrolled' ) }
					onDragLeave={ () => setDropTarget( null ) }
					onDrop={ () => onDropTo( 'enrolled' ) }
					locked={ locked }
					empty="No players enrolled yet."
					action={
						players.length > 0 &&
						canRemove && (
							<button
								type="button"
								onClick={ () => setConfirm( 'remove' ) }
								disabled={ busy }
								className="whitespace-nowrap rounded border border-rule px-3 py-1.5 text-sm text-loss hover:bg-surface disabled:opacity-40"
							>
								Remove all
							</button>
						)
					}
				/>

				{ ! locked && (
					<div className="flex justify-center">
						<button
							type="button"
							onClick={ () =>
								addMode
									? doEnroll( [ ...selAvailable ] )
									: doRemove( [ ...selEnrolled ] )
							}
							disabled={
								( ! addMode && ! removeMode ) ||
								busy ||
								( removeMode && ! canRemove )
							}
							className={
								'sticky top-28 mt-24 self-start whitespace-nowrap rounded px-3 py-2 text-sm font-medium disabled:opacity-40 ' +
								( removeMode
									? 'bg-loss text-paper hover:opacity-90'
									: 'bg-ink text-paper hover:bg-ink-2' )
							}
						>
							{ removeMode ? 'Remove →' : '← Add' }
						</button>
					</div>
				) }

				{ ! locked && (
					<TransferPanel
						title={ `Active players (${ available.length })` }
						side="available"
						rows={ available }
						rowId={ ( p ) => p.id }
						rowLabel={ ( p ) => p.name }
						rowMeta={ ( p ) => p.knsb_elo || '' }
						rowRating={ availableRating }
						selected={ selAvailable }
						search={ availableSearch }
						onSearch={ setAvailableSearch }
						onToggle={ toggle }
						onSelectRange={ selectRange }
						onDragStart={ onDragStart }
						isOver={ dropTarget === 'available' }
						onDragOver={ () => setDropTarget( 'available' ) }
						onDragLeave={ () => setDropTarget( null ) }
						onDrop={ () => onDropTo( 'available' ) }
						empty="No more players to add."
						action={
							addAllIds.length > 0 && (
								<button
									type="button"
									onClick={ () => setConfirm( 'add' ) }
									disabled={ busy }
									className="whitespace-nowrap rounded border border-rule px-3 py-1.5 text-sm text-ink-3 hover:bg-surface hover:text-ink disabled:opacity-40"
								>
									Add all
								</button>
							)
						}
					/>
				) }
			</div>

			{ err && (
				<p className="text-sm text-loss">{ errorMessage( err ) }</p>
			) }

			{ confirm && (
				<ConfirmModal
					title={
						confirm === 'remove'
							? 'Remove all players'
							: 'Add all players'
					}
					confirmLabel={
						confirm === 'remove' ? 'Remove all' : 'Add all'
					}
					danger={ confirm === 'remove' }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ confirmAction }
				>
					Are you sure you want to{ ' ' }
					{ confirm === 'remove' ? 'remove' : 'add' } all players?
				</ConfirmModal>
			) }
		</div>
	);
}
