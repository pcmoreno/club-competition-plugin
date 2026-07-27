import { useState, useMemo, useRef } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { SearchInput, ConfirmModal } from '../components/ui';
import { errorMessage } from './tournamentShared';

// ADMIN. Players (enrolment) tab, as a two-panel transfer list: Enrolled on the
// left, the remaining active club players on the right. Move players by
// dragging between panels or by multi-selecting and using the centre button
// (Add ← / Remove →). Enrolment ignores categories (they're assigned elsewhere);
// ratings auto-fill from the player's KNSB Elo.
export function TournamentPlayersTab( { season, players } ) {
	const queryClient = useQueryClient();

	const [ selEnrolled, setSelEnrolled ] = useState( () => new Set() );
	const [ selAvailable, setSelAvailable ] = useState( () => new Set() );
	const [ enrolledSearch, setEnrolledSearch ] = useState( '' );
	const [ availableSearch, setAvailableSearch ] = useState( '' );
	const [ dropTarget, setDropTarget ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const drag = useRef( null );

	const { data: allPlayers } = useQuery( {
		queryKey: [ 'admin-players' ],
		queryFn: () => api.get( 'players' ),
	} );

	const enrolledIds = useMemo(
		() => new Set( players.map( ( p ) => p.player_id ) ),
		[ players ]
	);

	const enrolled = useMemo( () => {
		const q = enrolledSearch.trim().toLowerCase();
		return [ ...players ]
			.filter( ( p ) => ! q || ( p.name ?? '' ).toLowerCase().includes( q ) )
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
			.filter( ( p ) => ! q || ( p.name ?? '' ).toLowerCase().includes( q ) )
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
		queryClient.invalidateQueries( { queryKey: [ 'season', String( season.id ) ] } );
		queryClient.invalidateQueries( { queryKey: [ 'seasons' ] } );
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

	const busy = enroll.isPending || remove.isPending;

	// Removing a player who has played orphans their games/attendance/snapshots,
	// so removal is only allowed while the tournament is still in preparation
	// (the server enforces the same rule).
	const canRemove = season.status === 'preparation';

	const doEnroll = ( ids ) => {
		if ( ids.length > 0 ) {
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
				next.has( id ) ? next.delete( id ) : next.add( id );
				return next;
			} );
		} else {
			setSelAvailable( new Set() );
			setSelEnrolled( ( prev ) => {
				const next = new Set( prev );
				next.has( id ) ? next.delete( id ) : next.add( id );
				return next;
			} );
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
			<div className="grid grid-cols-[1fr_auto_1fr] items-stretch gap-3">
				<Panel
					title={ `Enrolled (${ players.length })` }
					side="enrolled"
					rows={ enrolled }
					rowId={ ( p ) => p.player_id }
					rowLabel={ ( p ) => p.name }
					rowMeta={ ( p ) => {
						const cat = p.category ? ` (${ p.category })` : '';
						return `${ p.elo || '' }${ cat }`.trim();
					} }
					selected={ selEnrolled }
					search={ enrolledSearch }
					onSearch={ setEnrolledSearch }
					onToggle={ toggle }
					onDragStart={ onDragStart }
					isOver={ dropTarget === 'enrolled' }
					onDragOver={ () => setDropTarget( 'enrolled' ) }
					onDragLeave={ () => setDropTarget( null ) }
					onDrop={ () => onDropTo( 'enrolled' ) }
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

				<Panel
					title={ `Active players (${ available.length })` }
					side="available"
					rows={ available }
					rowId={ ( p ) => p.id }
					rowLabel={ ( p ) => p.name }
					rowMeta={ ( p ) => p.knsb_elo || '' }
					selected={ selAvailable }
					search={ availableSearch }
					onSearch={ setAvailableSearch }
					onToggle={ toggle }
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

// One column of the transfer list. Rows are click-to-toggle selectable and
// draggable; the whole panel is a drop target.
function Panel( {
	title,
	side,
	rows,
	rowId,
	rowLabel,
	rowMeta,
	selected,
	search,
	onSearch,
	onToggle,
	onDragStart,
	isOver,
	onDragOver,
	onDragLeave,
	onDrop,
	empty,
	action,
} ) {
	return (
		<section className="flex flex-col">
			<h3 className="mb-2 text-sm font-medium text-ink">{ title }</h3>
			<div className="mb-2 flex items-center gap-2">
				<div className="flex-1">
					<SearchInput
						value={ search }
						onChange={ onSearch }
						placeholder="Filter…"
					/>
				</div>
				{ action }
			</div>
			<ul
				onDragOver={ ( e ) => {
					e.preventDefault();
					onDragOver();
				} }
				onDragLeave={ onDragLeave }
				onDrop={ onDrop }
				className={
					'min-h-64 flex-1 space-y-1 rounded border bg-surface p-1.5 ' +
					( isOver
						? 'border-accent ring-1 ring-accent'
						: 'border-rule' )
				}
			>
				{ rows.length === 0 ? (
					<li className="px-2 py-6 text-center text-sm text-muted">
						{ empty }
					</li>
				) : (
					rows.map( ( p ) => {
						const id = rowId( p );
						const isSel = selected.has( id );
						const meta = rowMeta( p );
						return (
							<li
								key={ id }
								draggable
								onDragStart={ () => onDragStart( side, id ) }
								onClick={ () => onToggle( side, id ) }
								className={
									'flex cursor-pointer items-center justify-between rounded px-2 py-1.5 text-sm ' +
									( isSel
										? 'bg-accent-soft text-ink'
										: 'text-ink-3 hover:bg-paper' )
								}
							>
								<span className="truncate">{ rowLabel( p ) }</span>
								{ meta !== '' && (
									<span className="num ml-2 shrink-0 font-mono text-xs text-muted">
										{ meta }
									</span>
								) }
							</li>
						);
					} )
				) }
			</ul>
		</section>
	);
}
