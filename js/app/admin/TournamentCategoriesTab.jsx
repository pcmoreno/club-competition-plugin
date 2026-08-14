import { useState, useMemo, useRef } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { ConfirmModal } from '../components/ui';
import { fieldInput, primaryBtn, errorMessage } from './tournamentShared';
import { keys } from '../api/keys';

// ADMIN. Categories tab, and the Teams tab — one component, because a team
// tournament groups its players out of the same `categories` column and the
// same `season_players.category`. `season.is_team` only changes what the groups
// are called; nothing about the storage or the interaction differs.
//
// Add groups (persisted via PATCH /seasons/{id}) then assign enrolled players by
// dragging them between a box per group and the "Unassigned" box (PATCH
// /seasons/{id}/players/{pid}). The boxes double as the group list — each
// carries its own Remove (disabled while it still holds players). Rename isn't
// offered: players store the group as a plain string, so it'd orphan them.
export function TournamentCategoriesTab( { season, players, locked = false } ) {
	const isTeam = season.is_team === true;
	const term = isTeam ? 'team' : 'category';
	const plural = isTeam ? 'teams' : 'categories';
	const queryClient = useQueryClient();
	const [ list, setList ] = useState( season.categories ?? [] );
	const [ input, setInput ] = useState( '' );
	const [ over, setOver ] = useState( null );
	const [ confirming, setConfirming ] = useState( false );
	const drag = useRef( null );

	const seasonKey = keys.season( season.id );

	// Enrolled players split into a box per category plus the leftover pool,
	// each ordered by rating (highest first).
	const grouped = useMemo( () => {
		const byRating = ( a, b ) => ( b.elo || 0 ) - ( a.elo || 0 );
		// A team plays in board order; an unnumbered player sorts to the bottom.
		const byBoard = ( a, b ) =>
			( a.board_number ?? Infinity ) - ( b.board_number ?? Infinity ) ||
			byRating( a, b );
		const groups = Object.fromEntries( list.map( ( c ) => [ c, [] ] ) );
		const unassigned = [];
		for ( const p of players ) {
			if ( p.category && groups[ p.category ] ) {
				groups[ p.category ].push( p );
			} else {
				unassigned.push( p );
			}
		}
		for ( const c of list ) {
			groups[ c ].sort( isTeam ? byBoard : byRating );
		}
		unassigned.sort( byRating );
		return { groups, unassigned };
	}, [ players, list ] );

	// The list is optimistic: it updates immediately and persists in the
	// background; a failed save rolls back to the server's last-known value.
	const save = useMutation( {
		mutationFn: ( categories ) =>
			api.patch( `seasons/${ season.id }`, { categories } ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: seasonKey } );
			queryClient.invalidateQueries( { queryKey: keys.seasons() } );
		},
		onError: () => setList( season.categories ?? [] ),
	} );

	// Category assignment, optimistic against the cached season roster so the
	// boxes update the instant a player is dropped.
	const assign = useMutation( {
		mutationFn: ( { playerId, category } ) =>
			api.patch( `seasons/${ season.id }/players/${ playerId }`, {
				category,
			} ),
		onMutate: async ( { playerId, category } ) => {
			await queryClient.cancelQueries( { queryKey: seasonKey } );
			const prev = queryClient.getQueryData( seasonKey );
			queryClient.setQueryData( seasonKey, ( old ) =>
				old
					? {
							...old,
							players: old.players.map( ( p ) =>
								p.player_id === playerId
									? { ...p, category }
									: p
							),
					  }
					: old
			);
			return { prev };
		},
		onError: ( _e, _v, ctx ) => {
			if ( ctx?.prev ) {
				queryClient.setQueryData( seasonKey, ctx.prev );
			}
		},
		onSettled: () => {
			queryClient.invalidateQueries( { queryKey: seasonKey } );
			queryClient.invalidateQueries( { queryKey: keys.seasons() } );
		},
	} );

	// Bulk-apply many category assignments in one atomic request (Auto Fill),
	// optimistic like the single assign so the whole board rearranges immediately.
	const autoFill = useMutation( {
		mutationFn: ( assignments ) =>
			api.patch( `seasons/${ season.id }/players/bulk`, {
				assignments: assignments.map( ( a ) => ( {
					player_id: a.playerId,
					category: a.category,
				} ) ),
			} ),
		onMutate: async ( assignments ) => {
			await queryClient.cancelQueries( { queryKey: seasonKey } );
			const prev = queryClient.getQueryData( seasonKey );
			const byId = new Map(
				assignments.map( ( a ) => [ a.playerId, a.category ] )
			);
			queryClient.setQueryData( seasonKey, ( old ) =>
				old
					? {
							...old,
							players: old.players.map( ( p ) =>
								byId.has( p.player_id )
									? { ...p, category: byId.get( p.player_id ) }
									: p
							),
					  }
					: old
			);
			return { prev };
		},
		onError: ( _e, _v, ctx ) => {
			if ( ctx?.prev ) {
				queryClient.setQueryData( seasonKey, ctx.prev );
			}
		},
		onSettled: () => {
			queryClient.invalidateQueries( { queryKey: seasonKey } );
			queryClient.invalidateQueries( { queryKey: keys.seasons() } );
		},
	} );

	// A team's board order, sent as the players in playing order — the server
	// numbers them, so the request can't produce a gap or a shared board.
	const boards = useMutation( {
		mutationFn: ( { team, playerIds } ) =>
			api.patch( `seasons/${ season.id }/boards`, {
				team,
				player_ids: playerIds,
			} ),
		onMutate: async ( { playerIds } ) => {
			await queryClient.cancelQueries( { queryKey: seasonKey } );
			const prev = queryClient.getQueryData( seasonKey );
			const boardOf = new Map(
				playerIds.map( ( id, i ) => [ id, i + 1 ] )
			);
			queryClient.setQueryData( seasonKey, ( old ) =>
				old
					? {
							...old,
							players: old.players.map( ( p ) =>
								boardOf.has( p.player_id )
									? {
											...p,
											board_number: boardOf.get(
												p.player_id
											),
									  }
									: p
							),
					  }
					: old
			);
			return { prev };
		},
		onError: ( _e, _v, ctx ) => {
			if ( ctx?.prev ) {
				queryClient.setQueryData( seasonKey, ctx.prev );
			}
		},
		onSettled: () =>
			queryClient.invalidateQueries( { queryKey: seasonKey } ),
	} );

	// Even split by rating: strongest players fill the first group; when the
	// count doesn't divide evenly the remainder lands in the lowest groups, so
	// those end up one larger.
	const runAutoFill = () => {
		setConfirming( false );
		const sorted = [ ...players ].sort(
			( a, b ) => ( b.elo || 0 ) - ( a.elo || 0 )
		);
		const k = list.length;
		const base = Math.floor( sorted.length / k );
		const rem = sorted.length % k;
		const assignments = [];
		let idx = 0;
		for ( let i = 0; i < k; i++ ) {
			const size = base + ( i >= k - rem ? 1 : 0 );
			for ( let j = 0; j < size; j++ ) {
				assignments.push( {
					playerId: sorted[ idx ].player_id,
					category: list[ i ],
				} );
				idx++;
			}
		}
		autoFill.mutate( assignments );
	};

	const persist = ( next ) => {
		setList( next );
		save.mutate( next );
	};

	// An empty box adds the next unused name in the sequence, so a tournament
	// with plain A/B/C groups needs no typing at all.
	const nextName = () => {
		for ( let i = 0; ; i++ ) {
			const name = `${ isTeam ? 'Team' : 'Group' } ${ letters( i ) }`;
			if ( ! list.includes( name ) ) {
				return name;
			}
		}
	};

	const add = () => {
		const name = input.trim() === '' ? nextName() : input.trim();
		if ( list.includes( name ) ) {
			return;
		}
		persist( [ ...list, name ] );
		setInput( '' );
	};

	const removeAt = ( name ) => {
		if ( grouped.groups[ name ]?.length > 0 ) {
			return;
		}
		persist( list.filter( ( c ) => c !== name ) );
	};

	// A dragged player carries its current category so a drop onto its own box
	// is a no-op; null means the Unassigned box.
	const onDragStart = ( player ) => {
		drag.current = {
			playerId: player.player_id,
			from: player.category ?? null,
		};
	};

	const onDropTo = ( target ) => {
		const d = drag.current;
		drag.current = null;
		setOver( null );
		if ( ! d || ( d.from ?? null ) === ( target ?? null ) ) {
			return;
		}
		assign.mutate( { playerId: d.playerId, category: target } );
	};

	// Dropping onto a row places the dragged player at that board, pushing the
	// rest down. Only inside a team — categories have no order to hold.
	const onDropOnRow = ( target ) => {
		const d = drag.current;
		if ( ! d || ! isTeam || ! target.category ) {
			return;
		}
		drag.current = null;
		setOver( null );

		if ( d.from !== target.category ) {
			assign.mutate( {
				playerId: d.playerId,
				category: target.category,
			} );
			return;
		}
		if ( d.playerId === target.player_id ) {
			return;
		}

		const order = ( grouped.groups[ target.category ] ?? [] )
			.map( ( p ) => p.player_id )
			.filter( ( id ) => id !== d.playerId );
		order.splice( order.indexOf( target.player_id ), 0, d.playerId );
		boards.mutate( { team: target.category, playerIds: order } );
	};

	return (
		<div className="space-y-6">
			<p className="text-sm text-ink-3">
				{ locked
					? `The ${ plural } this tournament was played in, and who was in each.`
					: isTeam
					? 'Teams split the tournament into sides. Every player should end up in one.'
					: 'Categories split the tournament into pools. Leave the list empty to run it as a single undivided group.' }
			</p>

			{ /* Removed rather than disabled: nothing can be added to a finished record. */ }
			{ ! locked && (
			<div className="max-w-xl">
				<div className="flex items-end gap-3">
					<label className="block flex-1">
						<span className="mb-1 block text-xs uppercase tracking-wide text-muted">
							Add { term }
						</span>
						<input
							type="text"
							value={ input }
							onChange={ ( e ) => setInput( e.target.value ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' ) {
									e.preventDefault();
									add();
								}
							} }
							placeholder={ isTeam ? 'e.g. Team A' : 'e.g. Group A' }
							className={ fieldInput }
						/>
					</label>
					<button
						type="button"
						className={ primaryBtn }
						onClick={ add }
						disabled={ save.isPending }
					>
						Add
					</button>
					<button
						type="button"
						className="rounded border border-rule px-4 py-2 text-sm font-medium text-ink hover:bg-surface disabled:opacity-40"
						onClick={ () => setConfirming( true ) }
						disabled={
							list.length === 0 ||
							players.length === 0 ||
							autoFill.isPending
						}
						title={
							list.length === 0
								? `Add a ${ term } first`
								: players.length === 0
								? 'Enrol players first'
								: `Distribute players evenly across ${ plural }`
						}
					>
						{ autoFill.isPending ? 'Filling…' : 'Auto Fill' }
					</button>
				</div>
				{ save.isError && (
					<p className="mt-2 text-sm text-loss">
						{ errorMessage( save.error ) }
					</p>
				) }
			</div>
			) }

			<p className="text-xs text-muted">
				{ players.length } { players.length === 1 ? 'player' : 'players' }
				{ ' · ' }
				{ list.length } { list.length === 1 ? term : plural }
			</p>

			{ list.length > 0 && (
				<div
					className={
						'space-y-3' +
						( locked ? '' : ' border-t border-rule pt-6' )
					}
				>
					<div>
						<h3 className="text-sm font-medium text-ink">
							{ locked
								? `Players by ${ term }`
								: `Assign players to ${ plural }` }
						</h3>
						{ ! locked && (
							<p className="text-xs text-muted">
								Drag a player into a { term }, or back to
								Unassigned to clear it.
								{ isTeam &&
									' Drop one onto another to change the board order.' }
							</p>
						) }
					</div>

					{ players.length === 0 && ! locked && (
						<p className="text-sm text-muted">
							No players enrolled yet — add them on the Players
							tab. You can still add and remove { plural } here.
						</p>
					) }

					{ /* Boxes render even with no players so the category list
					     (and each box's Remove) is always reachable. */ }
					<div className="grid grid-cols-[2fr_1fr] items-start gap-4">
						<div className="flex flex-col gap-4">
							{ list.map( ( c ) => (
								<AssignBox
									key={ c }
									title={ c }
									rows={ grouped.groups[ c ] }
									isOver={ over === c }
									onOver={ () => setOver( c ) }
									onLeave={ () => setOver( null ) }
									onDrop={ () => onDropTo( c ) }
									onDragStart={ onDragStart }
									onDropOnRow={ isTeam ? onDropOnRow : null }
									showBoards={ isTeam }
									onRemove={ () => removeAt( c ) }
									locked={ locked }
									empty="Drop players here"
								/>
							) ) }
						</div>
						<AssignBox
							title="Unassigned"
							rows={ grouped.unassigned }
							isOver={ over === '__none' }
							onOver={ () => setOver( '__none' ) }
							onLeave={ () => setOver( null ) }
							onDrop={ () => onDropTo( null ) }
							onDragStart={ onDragStart }
							locked={ locked }
							empty="No unassigned players"
						/>
					</div>

					{ ( assign.isError || autoFill.isError ) && (
						<p className="text-sm text-loss">
							{ errorMessage( assign.error || autoFill.error ) }
						</p>
					) }
				</div>
			) }

			{ confirming && (
				<ConfirmModal
					title="Auto Fill"
					confirmLabel="Auto Fill"
					onCancel={ () => setConfirming( false ) }
					onConfirm={ runAutoFill }
				>
					This assigns an even number of players to each { term } by
					rating and overrides what you have set so far. When the count
					doesn’t divide evenly, the lowest { plural } take the extra
					players.
				</ConfirmModal>
			) }
		</div>
	);
}

// One droppable box holding the players in a category (or the unassigned pool).
// Rows are draggable to any other box. Category boxes carry a Remove action,
// disabled while the box still holds players. Locked leaves a plain list.
function AssignBox( {
	title,
	rows,
	isOver,
	onOver,
	onLeave,
	onDrop,
	onDragStart,
	onDropOnRow = null,
	showBoards = false,
	onRemove,
	locked = false,
	empty,
} ) {
	return (
		<section
			onDragOver={
				locked
					? undefined
					: ( e ) => {
							e.preventDefault();
							onOver();
					  }
			}
			onDragLeave={ locked ? undefined : onLeave }
			onDrop={ locked ? undefined : onDrop }
			className={
				'rounded border bg-surface ' +
				( isOver ? 'border-accent ring-1 ring-accent' : 'border-rule' )
			}
		>
			<div className="flex items-center justify-between border-b border-rule-soft px-3 py-2">
				<span className="text-sm font-medium text-ink">{ title }</span>
				<div className="flex items-center gap-3">
					<span className="num font-mono text-xs text-muted">
						{ rows.length }
					</span>
					{ onRemove && ! locked && (
						<button
							type="button"
							className="text-xs text-loss hover:underline disabled:opacity-40"
							onClick={ onRemove }
							disabled={ rows.length > 0 }
							title={
								rows.length > 0
									? 'Move its players out first'
									: 'Remove'
							}
						>
							Remove
						</button>
					) }
				</div>
			</div>
			<ul className="min-h-20 space-y-1 p-1.5">
				{ rows.length === 0 ? (
					<li className="px-2 py-4 text-center text-xs text-muted">
						{ empty }
					</li>
				) : (
					rows.map( ( p, i ) => (
						<li
							key={ p.player_id }
							draggable={ ! locked }
							onDragStart={
								locked ? undefined : () => onDragStart( p )
							}
							onDragOver={
								locked || ! onDropOnRow
									? undefined
									: ( e ) => e.preventDefault()
							}
							onDrop={
								locked || ! onDropOnRow
									? undefined
									: ( e ) => {
											e.stopPropagation();
											onDropOnRow( p );
									  }
							}
							className={
								'flex items-center justify-between rounded px-2 py-1.5 text-sm text-ink-3 ' +
								( locked ? '' : 'cursor-grab hover:bg-paper' )
							}
						>
							<span className="flex min-w-0 items-baseline gap-2">
								{ showBoards && (
									<span className="num w-4 shrink-0 text-right font-mono text-xs text-muted">
										{ p.board_number ?? i + 1 }
									</span>
								) }
								<span className="truncate">{ p.name }</span>
							</span>
							{ !! p.elo && (
								<span className="num ml-2 shrink-0 font-mono text-xs text-muted">
									{ p.elo }
								</span>
							) }
						</li>
					) )
				) }
			</ul>
		</section>
	);
}

// Spreadsheet-style sequence: A…Z, then AA, AB — so naming can't run out.
function letters( n ) {
	let out = '';
	for ( let i = n; i >= 0; i = Math.floor( i / 26 ) - 1 ) {
		out = String.fromCharCode( 65 + ( i % 26 ) ) + out;
	}
	return out;
}
