import { useState, useEffect, useMemo, useRef } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { Notice } from '../components/ui';
import {
	ROUND_STATUS_LABELS,
	ROUND_EDITABLE,
	generateLabel,
	errorMessage,
} from './tournamentShared';

// ADMIN. Pairings tab of the tournament detail page (default tab while active).
// A manual round + pairings manager: create/select a round, build the board by
// dragging enrolled players (listed at the bottom) into White/Black slots, enter
// results, and advance the round status. Automatic generation is cadence-aware
// (per-round vs full schedule) but only Manual pairing exists today, so the
// Generate control is a disabled placeholder for the auto engines.
export function TournamentPairingsTab( { season, players } ) {
	const queryClient = useQueryClient();
	const [ selectedRoundId, setSelectedRoundId ] = useState( null );
	const [ builder, setBuilder ] = useState( { white: null, black: null } );
	const [ overSlot, setOverSlot ] = useState( null );
	const drag = useRef( null );

	const roundsKey = [ 'rounds', String( season.id ) ];

	const { data: rounds, isLoading: roundsLoading } = useQuery( {
		queryKey: roundsKey,
		queryFn: () => api.get( `seasons/${ season.id }/rounds` ),
	} );

	// Default to the latest round; the admin can switch to an earlier one.
	const ordered = useMemo(
		() =>
			( Array.isArray( rounds ) ? [ ...rounds ] : [] ).sort(
				( a, b ) => a.round_number - b.round_number
			),
		[ rounds ]
	);
	const currentRoundId =
		selectedRoundId ?? ( ordered.length ? ordered[ ordered.length - 1 ].id : null );

	const { data: roundData } = useQuery( {
		queryKey: [ 'round', String( currentRoundId ) ],
		queryFn: () => api.get( `rounds/${ currentRoundId }` ),
		enabled: currentRoundId !== null,
	} );

	const round = roundData?.round ?? null;
	const games = roundData?.games ?? [];
	const editable = round !== null && ROUND_EDITABLE.includes( round.status );

	// Clearing the builder whenever the round changes avoids a slot carrying a
	// half-made pairing across rounds.
	useEffect( () => {
		setBuilder( { white: null, black: null } );
	}, [ currentRoundId ] );

	const roundKey = [ 'round', String( currentRoundId ) ];
	const invalidateRound = () =>
		queryClient.invalidateQueries( { queryKey: roundKey } );

	const createRound = useMutation( {
		mutationFn: () => api.post( `seasons/${ season.id }/rounds`, {} ),
		onSuccess: ( created ) => {
			queryClient.invalidateQueries( { queryKey: roundsKey } );
			if ( created?.id ) {
				setSelectedRoundId( created.id );
			}
		},
	} );

	const createGame = useMutation( {
		mutationFn: ( { white, black } ) =>
			api.post( `rounds/${ currentRoundId }/games`, {
				white_season_player_id: white,
				black_season_player_id: black,
			} ),
		onSuccess: invalidateRound,
	} );

	const deleteGame = useMutation( {
		mutationFn: ( id ) => api.del( `games/${ id }` ),
		onSuccess: invalidateRound,
	} );

	const swapGame = useMutation( {
		mutationFn: ( g ) =>
			api.put( `games/${ g.id }`, {
				white_season_player_id: g.black.season_player_id,
				black_season_player_id: g.white.season_player_id,
			} ),
		onSuccess: invalidateRound,
	} );

	const setResult = useMutation( {
		mutationFn: ( { id, result } ) =>
			api.patch( `games/${ id }/result`, { result } ),
		onSuccess: invalidateRound,
	} );

	const setStatus = useMutation( {
		mutationFn: ( status ) =>
			api.patch( `rounds/${ currentRoundId }/status`, { status } ),
		onSuccess: () => {
			invalidateRound();
			queryClient.invalidateQueries( { queryKey: roundsKey } );
			queryClient.invalidateQueries( { queryKey: [ 'season', String( season.id ) ] } );
		},
	} );

	// season_player ids already placed this round (on a board or in the builder),
	// plus a lookup from player → board number for the pool's "on board N" tag.
	const { pairedIds, boardOf } = useMemo( () => {
		const paired = new Set();
		const board = {};
		for ( const g of games ) {
			if ( g.white ) {
				paired.add( g.white.season_player_id );
				board[ g.white.season_player_id ] = g.board;
			}
			if ( g.black ) {
				paired.add( g.black.season_player_id );
				board[ g.black.season_player_id ] = g.board;
			}
		}
		if ( builder.white ) {
			paired.add( builder.white.season_player_id );
		}
		if ( builder.black ) {
			paired.add( builder.black.season_player_id );
		}
		return { pairedIds: paired, boardOf: board };
	}, [ games, builder ] );

	// Enrolled players, strongest first — the order you pair a manual board in.
	const pool = useMemo(
		() =>
			[ ...players ].sort( ( a, b ) => ( b.elo || 0 ) - ( a.elo || 0 ) ),
		[ players ]
	);
	const unpaired = pool.filter( ( p ) => ! pairedIds.has( p.season_player_id ) );

	const dropToSlot = ( slot ) => {
		const p = drag.current;
		drag.current = null;
		setOverSlot( null );
		if ( ! p || ! editable ) {
			return;
		}
		const other = slot === 'white' ? builder.black : builder.white;
		if ( other && other.season_player_id === p.season_player_id ) {
			return;
		}
		const next =
			slot === 'white'
				? { white: p, black: builder.black }
				: { white: builder.white, black: p };
		if ( next.white && next.black ) {
			createGame.mutate( {
				white: next.white.season_player_id,
				black: next.black.season_player_id,
			} );
			setBuilder( { white: null, black: null } );
		} else {
			setBuilder( next );
		}
	};

	if ( roundsLoading ) {
		return <Notice>Loading…</Notice>;
	}

	// No rounds yet — offer to create the first one.
	if ( ordered.length === 0 ) {
		return (
			<div className="space-y-4">
				<p className="text-sm text-ink-3">
					No rounds yet. Create the first round to start pairing.
				</p>
				<button
					type="button"
					onClick={ () => createRound.mutate() }
					disabled={ createRound.isPending }
					className="rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60"
				>
					{ createRound.isPending ? 'Creating…' : 'Create first round' }
				</button>
				{ createRound.isError && (
					<p className="text-sm text-loss">
						{ errorMessage( createRound.error ) }
					</p>
				) }
			</div>
		);
	}

	const genLabel = generateLabel( season.cadence );

	return (
		<div className="space-y-6">
			{ /* Round bar */ }
			<div className="flex flex-wrap items-center gap-3 border-b border-rule pb-4">
				<div className="flex flex-wrap items-center gap-1">
					{ ordered.map( ( r ) => (
						<button
							key={ r.id }
							type="button"
							onClick={ () => setSelectedRoundId( r.id ) }
							className={
								'rounded px-3 py-1.5 text-sm font-medium ' +
								( r.id === currentRoundId
									? 'bg-ink text-paper'
									: 'text-ink-3 hover:bg-surface hover:text-ink' )
							}
						>
							Round { r.round_number }
						</button>
					) ) }
				</div>

				<button
					type="button"
					onClick={ () => createRound.mutate() }
					disabled={ createRound.isPending }
					className="rounded border border-rule px-3 py-1.5 text-sm text-ink-3 hover:bg-surface hover:text-ink disabled:opacity-40"
				>
					{ createRound.isPending ? 'Adding…' : '+ New round' }
				</button>

				<div className="ml-auto flex items-center gap-3">
					{ genLabel && (
						<button
							type="button"
							disabled
							title="Automatic pairing isn’t available yet — build the board by hand below."
							className="rounded border border-rule px-3 py-1.5 text-sm text-muted opacity-60"
						>
							{ genLabel }
						</button>
					) }
					<label className="flex items-center gap-2 text-sm text-ink-3">
						<span className="text-xs uppercase tracking-wide text-muted">
							Status
						</span>
						<select
							value={ round?.status ?? 'draft' }
							onChange={ ( e ) => setStatus.mutate( e.target.value ) }
							disabled={ setStatus.isPending }
							className="rounded border border-rule bg-surface px-2 py-1 text-sm text-ink disabled:opacity-60"
						>
							{ Object.entries( ROUND_STATUS_LABELS ).map(
								( [ value, label ] ) => (
									<option key={ value } value={ value }>
										{ label }
									</option>
								)
							) }
						</select>
					</label>
				</div>
			</div>

			{ season.cadence && season.cadence !== 'manual' && (
				<Notice>
					This tournament uses{ ' ' }
					{ season.cadence === 'full'
						? 'a full-schedule'
						: 'a per-round' }{ ' ' }
					pairing system. Automatic generation and the manual-override
					setting aren’t available yet — pairings can be entered by hand
					below in the meantime.
				</Notice>
			) }

			{ ! editable && round && (
				<Notice>
					Round { round.round_number } is{ ' ' }
					{ ROUND_STATUS_LABELS[ round.status ]?.toLowerCase() }, so its
					pairings are locked. Set it back to Published to edit.
				</Notice>
			) }

			{ /* Boards */ }
			<div className="space-y-2">
				{ games.length === 0 && ! builder.white && ! builder.black && (
					<p className="text-sm text-muted">
						No pairings yet. Drag players from the list below into the
						White and Black slots.
					</p>
				) }

				{ games.map( ( g ) => (
					<Board
						key={ g.id }
						game={ g }
						editable={ editable }
						onResult={ ( result ) =>
							setResult.mutate( {
								id: g.id,
								result: g.result === result ? null : result,
							} )
						}
						onSwap={ () => swapGame.mutate( g ) }
						onRemove={ () => deleteGame.mutate( g.id ) }
					/>
				) ) }

				{ editable && (
					<BuilderBoard
						board={ games.length + 1 }
						builder={ builder }
						overSlot={ overSlot }
						onOver={ setOverSlot }
						onDrop={ dropToSlot }
						onClear={ ( slot ) =>
							setBuilder( ( prev ) => ( { ...prev, [ slot ]: null } ) )
						}
					/>
				) }
			</div>

			{ ( createGame.isError || setResult.isError || deleteGame.isError ) && (
				<p className="text-sm text-loss">
					{ errorMessage(
						createGame.error || setResult.error || deleteGame.error
					) }
				</p>
			) }

			{ /* Enrolled players pool */ }
			<div className="border-t border-rule pt-4">
				<div className="mb-2 flex items-baseline justify-between">
					<h3 className="text-sm font-medium text-ink">
						Enrolled players
					</h3>
					<span className="text-xs text-muted">
						{ unpaired.length } unpaired
						{ unpaired.length % 2 === 1 &&
							' · odd one out takes a bye' }
					</span>
				</div>
				<ul className="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:grid-cols-4">
					{ pool.map( ( p ) => {
						const placed = pairedIds.has( p.season_player_id );
						return (
							<li
								key={ p.season_player_id }
								draggable={ editable && ! placed }
								onDragStart={ () => {
									drag.current = p;
								} }
								className={
									'flex items-center justify-between rounded border px-2 py-1.5 text-sm ' +
									( placed
										? 'border-rule-soft bg-surface text-muted'
										: editable
										? 'cursor-grab border-rule bg-paper text-ink-3 hover:border-accent'
										: 'border-rule bg-surface text-ink-3' )
								}
							>
								<span className="truncate">
									{ p.name }
									{ p.category ? (
										<span className="ml-1 text-muted">
											({ p.category })
										</span>
									) : null }
								</span>
								<span className="ml-2 flex shrink-0 items-center gap-2">
									{ placed && (
										<span className="text-[11px] text-muted">
											board { boardOf[ p.season_player_id ] }
										</span>
									) }
									{ !! p.elo && (
										<span className="num font-mono text-xs text-muted">
											{ p.elo }
										</span>
									) }
								</span>
							</li>
						);
					} ) }
				</ul>
			</div>
		</div>
	);
}

// A persisted pairing: White vs Black, with a result control, colour swap and
// remove. Locked (read-only result view) once the round isn't editable.
function Board( { game, editable, onResult, onSwap, onRemove } ) {
	return (
		<div className="flex items-center gap-3 rounded border border-rule bg-surface px-3 py-2">
			<span className="num w-8 shrink-0 font-mono text-xs text-muted">
				{ game.board }
			</span>
			<div className="grid flex-1 grid-cols-[1fr_auto_1fr] items-center gap-2">
				<span className="truncate text-right text-sm text-ink">
					{ game.white?.name ?? '—' }
				</span>
				<span className="text-xs text-muted">vs</span>
				<span className="truncate text-sm text-ink">
					{ game.black?.name ?? '—' }
				</span>
			</div>
			<ResultControl
				result={ game.result }
				disabled={ ! editable }
				onResult={ onResult }
			/>
			{ editable && (
				<div className="flex shrink-0 items-center gap-1">
					<button
						type="button"
						onClick={ onSwap }
						title="Swap colours"
						className="rounded px-1.5 py-1 text-xs text-ink-3 hover:bg-paper hover:text-ink"
					>
						⇄
					</button>
					<button
						type="button"
						onClick={ onRemove }
						title="Remove pairing"
						className="rounded px-1.5 py-1 text-xs text-loss hover:bg-loss/10"
					>
						×
					</button>
				</div>
			) }
		</div>
	);
}

// Segmented 1–0 / ½–½ / 0–1 control. Values map to GameResult (white/draw/black).
function ResultControl( { result, disabled, onResult } ) {
	const opts = [
		{ value: 'white', label: '1–0' },
		{ value: 'draw', label: '½–½' },
		{ value: 'black', label: '0–1' },
	];
	return (
		<div className="flex shrink-0 overflow-hidden rounded border border-rule">
			{ opts.map( ( o ) => (
				<button
					key={ o.value }
					type="button"
					disabled={ disabled }
					onClick={ () => onResult( o.value ) }
					className={
						'num px-2 py-1 font-mono text-xs disabled:opacity-60 ' +
						( result === o.value
							? 'bg-ink text-paper'
							: 'bg-paper text-ink-3 hover:bg-surface' )
					}
				>
					{ o.label }
				</button>
			) ) }
		</div>
	);
}

// The pending pairing being assembled: two droppable slots. Filling both creates
// the game and resets, so this is always the empty next board.
function BuilderBoard( { board, builder, overSlot, onOver, onDrop, onClear } ) {
	return (
		<div className="flex items-center gap-3 rounded border border-dashed border-rule px-3 py-2">
			<span className="num w-8 shrink-0 font-mono text-xs text-muted">
				{ board }
			</span>
			<div className="grid flex-1 grid-cols-[1fr_auto_1fr] items-center gap-2">
				<Slot
					side="white"
					label="White"
					player={ builder.white }
					isOver={ overSlot === 'white' }
					onOver={ onOver }
					onDrop={ onDrop }
					onClear={ onClear }
				/>
				<span className="text-xs text-muted">vs</span>
				<Slot
					side="black"
					label="Black"
					player={ builder.black }
					isOver={ overSlot === 'black' }
					onOver={ onOver }
					onDrop={ onDrop }
					onClear={ onClear }
				/>
			</div>
		</div>
	);
}

function Slot( { side, label, player, isOver, onOver, onDrop, onClear } ) {
	return (
		<div
			onDragOver={ ( e ) => {
				e.preventDefault();
				onOver( side );
			} }
			onDragLeave={ () => onOver( null ) }
			onDrop={ () => onDrop( side ) }
			className={
				'flex min-h-8 items-center justify-between rounded border px-2 py-1 text-sm ' +
				( side === 'white' ? 'text-right ' : '' ) +
				( isOver
					? 'border-accent bg-accent-soft'
					: player
					? 'border-rule bg-surface text-ink'
					: 'border-dashed border-rule text-muted' )
			}
		>
			{ player ? (
				<>
					<span className="truncate">{ player.name }</span>
					<button
						type="button"
						onClick={ () => onClear( side ) }
						className="ml-2 shrink-0 text-xs text-muted hover:text-loss"
						title="Clear"
					>
						×
					</button>
				</>
			) : (
				<span className="flex-1">Drop { label }…</span>
			) }
		</div>
	);
}
