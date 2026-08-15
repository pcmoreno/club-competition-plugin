import { useState, useMemo, useRef } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { ConfirmModal } from '../components/ui';
import {
	fieldInput,
	primaryBtn,
	errorMessage,
	isTeamLocked,
} from './tournamentShared';
import { keys } from '../api/keys';

// ADMIN. Categories tab, and the Teams tab — one component, because a season is
// one or the other and both group their players out of the `categories` column.
// A team season's column also holds the line-ups, but that's the server's
// business: each enrolment arrives carrying its group and board either way.
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
	const [ confirming, setConfirming ] = useState( null );
	const drag = useRef( null );

	const seasonKey = keys.season( season.id );

	const assigned = players.filter( ( p ) => p.category ).length;

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

	// Bulk-apply many assignments in one atomic request — Auto Fill and Clear are
	// the same write with different lists. Optimistic like the single assign, so
	// the whole board rearranges immediately.
	const assignMany = useMutation( {
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

	// The two modes want opposite things from the same ratings.
	//
	// Categories are strength tiers, so they take a straight split: the strongest
	// players fill the first group, and an uneven count leaves the lowest groups
	// one larger.
	//
	// Teams are meant to be evenly matched, so they take a snake draft — the pick
	// order reverses every pass (A B C D, D C B A, A B C D…), which is what stops
	// the first team from collecting every top board.
	const runAutoFill = () => {
		setConfirming( null );
		const sorted = [ ...players ].sort(
			( a, b ) => ( b.elo || 0 ) - ( a.elo || 0 )
		);
		const k = list.length;
		const assignments = [];

		if ( isTeam ) {
			sorted.forEach( ( p, i ) => {
				const pass = Math.floor( i / k );
				const seat = i % k;
				assignments.push( {
					playerId: p.player_id,
					category: list[ pass % 2 === 0 ? seat : k - 1 - seat ],
				} );
			} );
		} else {
			const base = Math.floor( sorted.length / k );
			const rem = sorted.length % k;
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
		}

		assignMany.mutate( assignments );
	};

	// Empty every group, leaving the groups themselves in place — each box
	// keeps its own Remove for that.
	const runClear = () => {
		setConfirming( null );
		assignMany.mutate(
			players.map( ( p ) => ( {
				playerId: p.player_id,
				category: null,
			} ) )
		);
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

	// Drag and drop isn't obvious on a list, so a board can also be nudged one
	// place at a time.
	const moveBoard = ( player, delta ) => {
		const team = player.category;
		if ( ! isTeam || ! team ) {
			return;
		}
		const order = ( grouped.groups[ team ] ?? [] ).map(
			( p ) => p.player_id
		);
		const from = order.indexOf( player.player_id );
		const to = from + delta;
		if ( from < 0 || to < 0 || to >= order.length ) {
			return;
		}
		order.splice( to, 0, order.splice( from, 1 )[ 0 ] );
		boards.mutate( { team, playerIds: order } );
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
				{ isTeamLocked( season ) && season.status !== 'completed'
					? 'Teams and board order are fixed now that the tournament has started.'
					: locked
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
						onClick={ () => setConfirming( 'autofill' ) }
						disabled={
							list.length === 0 ||
							players.length === 0 ||
							assignMany.isPending
						}
						title={
							list.length === 0
								? `Add a ${ term } first`
								: players.length === 0
								? 'Enrol players first'
								: isTeam
								? 'Balance the teams by rating'
								: `Distribute players evenly across ${ plural }`
						}
					>
						{ assignMany.isPending ? 'Filling…' : 'Auto Fill' }
					</button>
					<button
						type="button"
						className="rounded border border-rule px-4 py-2 text-sm font-medium text-loss hover:bg-surface disabled:opacity-40"
						onClick={ () => setConfirming( 'clear' ) }
						disabled={ assigned === 0 || assignMany.isPending }
						title={
							assigned === 0
								? 'Nobody is assigned'
								: `Take every player back out of their ${ term }`
						}
					>
						Clear
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
									' Change the board order with the arrows, or by dropping one player onto another.' }
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
									onMove={ isTeam && ! locked ? moveBoard : null }
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

					{ ( assign.isError || assignMany.isError || boards.isError ) && (
						<p className="text-sm text-loss">
							{ errorMessage(
								assign.error || assignMany.error || boards.error
							) }
						</p>
					) }
				</div>
			) }

			{ confirming === 'clear' && (
				<ConfirmModal
					title={ `Clear ${ plural }` }
					confirmLabel="Clear"
					danger
					onCancel={ () => setConfirming( null ) }
					onConfirm={ runClear }
				>
					This takes { assigned } { assigned === 1 ? 'player' : 'players' }{ ' ' }
					back out of their { plural }. The { plural } themselves stay.
				</ConfirmModal>
			) }

			{ confirming === 'autofill' && (
				<ConfirmModal
					title="Auto Fill"
					confirmLabel="Auto Fill"
					onCancel={ () => setConfirming( null ) }
					onConfirm={ runAutoFill }
				>
					{ isTeam
						? 'This shares the players out by rating so the teams are as evenly matched as possible, and overrides what you have set so far. Board order is reset to strongest first.'
						: 'This assigns an even number of players to each category by rating and overrides what you have set so far. When the count doesn’t divide evenly, the lowest categories take the extra players.' }
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
	onMove = null,
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
							<span className="ml-2 flex shrink-0 items-center gap-1">
								{ !! p.elo && (
									<span className="num font-mono text-xs text-muted">
										{ p.elo }
									</span>
								) }
								{ onMove && (
									<>
										<MoveButton
											label={ `Move ${ p.name } up a board` }
											disabled={ i === 0 }
											onClick={ () => onMove( p, -1 ) }
										>
											↑
										</MoveButton>
										<MoveButton
											label={ `Move ${ p.name } down a board` }
											disabled={ i === rows.length - 1 }
											onClick={ () => onMove( p, 1 ) }
										>
											↓
										</MoveButton>
									</>
								) }
							</span>
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

// One board nudge. Kept off the row's drag handle so a click can't start a drag.
function MoveButton( { label, disabled, onClick, children } ) {
	return (
		<button
			type="button"
			aria-label={ label }
			title={ label }
			disabled={ disabled }
			draggable={ false }
			onDragStart={ ( e ) => e.preventDefault() }
			onClick={ ( e ) => {
				e.stopPropagation();
				onClick();
			} }
			className="rounded px-1 text-xs leading-none text-muted hover:bg-paper hover:text-ink disabled:invisible"
		>
			{ children }
		</button>
	);
}
