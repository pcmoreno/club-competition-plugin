import { useState, useEffect, useMemo, useRef } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { Notice, ConfirmModal } from '../components/ui';
import { categoryLabel } from '../components/game';
import {
	ROUND_STATUS_LABELS,
	ROUND_EDITABLE,
	ROUND_ADVANCE_LABELS,
	nextRoundStatus,
	generateLabel,
	errorMessage,
} from './tournamentShared';
import { keys } from '../api/keys';

// ADMIN. Pairings tab of the tournament detail page (default tab while active).
// A manual round + pairings manager: create/select a round, build the board by
// dragging enrolled players (listed at the bottom) into White/Black slots, enter
// results, and advance the round status. Automatic generation is cadence-aware:
// a full-schedule system (round-robin) builds every round in one call, while the
// per-round engines don't exist yet and keep a disabled placeholder.
export function TournamentPairingsTab( { season, players, locked = false } ) {
	const queryClient = useQueryClient();
	const [ selectedRoundId, setSelectedRoundId ] = useState( null );
	const [ builder, setBuilder ] = useState( { white: null, black: null } );
	const [ overSlot, setOverSlot ] = useState( null );
	const [ poolOver, setPoolOver ] = useState( false );
	const [ byeOver, setByeOver ] = useState( null );
	const [ confirmAdvance, setConfirmAdvance ] = useState( false );
	const [ confirmReopen, setConfirmReopen ] = useState( false );
	const [ confirmGenerate, setConfirmGenerate ] = useState( false );
	// The round's date, held locally so the input doesn't snap back to the stored
	// value between the change and the refetch that confirms it.
	const [ dateDraft, setDateDraft ] = useState( null );
	// The player being dragged: { from: 'pool' | 'board', player, gameId? }.
	const drag = useRef( null );

	const roundsKey = keys.rounds( season.id );

	const { data: rounds, isLoading: roundsLoading } = useQuery( {
		queryKey: roundsKey,
		queryFn: () => api.get( `seasons/${ season.id }/rounds` ),
	} );

	// The tournament's round limit lives in its pairing settings (null = no
	// limit). The backend refuses a round past it either way; this is so the
	// button doesn't offer one it can't create.
	const { data: settings } = useQuery( {
		queryKey: keys.seasonSettings( season.id ),
		queryFn: () => api.get( `seasons/${ season.id }/settings` ),
	} );
	const roundLimit = settings?.pairing?.values?.numberOfRounds ?? null;

	// Default to the latest round; the admin can switch to an earlier one.
	const ordered = useMemo(
		() =>
			( Array.isArray( rounds ) ? [ ...rounds ] : [] ).sort(
				( a, b ) => a.round_number - b.round_number
			),
		[ rounds ]
	);
	// Fall back to the latest round unless the selection is actually in this
	// tournament's list. Today this tab remounts per tournament so a selection
	// can't outlive its season, but the `??` alone would happily keep one and
	// fetch a round belonging to a tournament no longer on screen.
	const currentRoundId = ordered.some( ( r ) => r.id === selectedRoundId )
		? selectedRoundId
		: ordered[ ordered.length - 1 ]?.id ?? null;

	// The round immediately before the selected one — its frozen snapshot is the
	// score each player carries *into* this round.
	const previousRoundId = useMemo( () => {
		const i = ordered.findIndex( ( r ) => r.id === currentRoundId );
		return i > 0 ? ordered[ i - 1 ].id : null;
	}, [ ordered, currentRoundId ] );

	const { data: roundData } = useQuery( {
		queryKey: keys.round( currentRoundId ),
		queryFn: () => api.get( `rounds/${ currentRoundId }` ),
		enabled: currentRoundId !== null,
	} );

	// Board scores are "entering this round" — the standings frozen after the
	// *previous* round, so they stay fixed once this round is scored (scoring
	// round N mustn't change round N's board numbers). rank_score is resolved
	// server-side from the season's RANK BY setting (points, TPR, …). The first
	// round has no previous snapshot, so everyone enters on 0.
	const { data: standingsData } = useQuery( {
		queryKey: keys.standings( season.id, previousRoundId ),
		queryFn: () =>
			api.get( `seasons/${ season.id }/standings`, {
				params: { round: previousRoundId },
			} ),
		enabled: previousRoundId !== null,
	} );
	const scoreOf = useMemo( () => {
		const map = {};
		for ( const r of standingsData?.standings ?? [] ) {
			map[ r.season_player_id ] = r.rank_score;
		}
		return map;
	}, [ standingsData ] );

	// Bye types the season defines (scoring settings), minus the reserved pairing
	// bye which the engine assigns to the odd player automatically.
	const { data: settingsData } = useQuery( {
		queryKey: keys.seasonSettings( season.id ),
		queryFn: () => api.get( `seasons/${ season.id }/settings` ),
	} );
	const byeTypes = useMemo(
		() =>
			( settingsData?.scoring?.values?.byeTypes ?? [] ).filter(
				( b ) => ! b.reserved
			),
		[ settingsData ]
	);

	const round = roundData?.round ?? null;
	const games = roundData?.games ?? [];
	// Phases: draft/published build the board (pairings editable, no results);
	// finalised locks the board and opens result entry; complete freezes the
	// standings and shows results read-only.
	const editable =
		! locked && round !== null && ROUND_EDITABLE.includes( round.status );
	// Generating reads the standings, and those are written when a round is
	// completed — so an earlier round still open means pairing from a ranking
	// that predates it. The service refuses this too; naming the round here is
	// what turns that refusal into something the admin can act on.
	const blockingRound = useMemo( () => {
		if ( round === null ) {
			return null;
		}

		return (
			ordered.find(
				( r ) =>
					r.round_number < round.round_number &&
					r.status !== 'complete'
			)?.round_number ?? null
		);
	}, [ ordered, round ] );
	const resultsVisible =
		round !== null &&
		( round.status === 'finalised' || round.status === 'complete' );
	const resultsOpen = round !== null && round.status === 'finalised';

	// Clearing the builder whenever the round changes avoids a slot carrying a
	// half-made pairing across rounds; the date draft belongs to one round too.
	useEffect( () => {
		setBuilder( { white: null, black: null } );
		setDateDraft( null );
	}, [ currentRoundId ] );

	const roundKey = keys.round( currentRoundId );
	const invalidateRound = () =>
		queryClient.invalidateQueries( { queryKey: roundKey } );

	const createRound = useMutation( {
		mutationFn: () => api.post( `seasons/${ season.id }/rounds`, {} ),
		onSuccess: ( created ) => {
			if ( created?.id ) {
				// Seed the list before selecting: the selection is only honoured
				// when it's found in `ordered`, so without this the tab falls
				// back to the previous round until the refetch lands.
				queryClient.setQueryData( roundsKey, ( prev ) =>
					Array.isArray( prev ) ? [ ...prev, created ] : [ created ]
				);
				setSelectedRoundId( created.id );
			}
			queryClient.invalidateQueries( { queryKey: roundsKey } );
		},
	} );

	// Full-schedule systems build every round at once. The backend refuses to
	// rebuild once a round has left draft, so this can't rewrite boards players
	// have already seen.
	const generateSchedule = useMutation( {
		mutationFn: () => api.post( `seasons/${ season.id }/rounds/generate`, {} ),
		onSuccess: ( created ) => {
			setConfirmGenerate( false );
			setSelectedRoundId( created?.rounds?.[ 0 ]?.id ?? null );
			queryClient.invalidateQueries( { queryKey: roundsKey } );
			queryClient.invalidateQueries( { queryKey: keys.season( season.id ) } );
		},
	} );

	// Per-round systems pair the selected round from the standings. The backend
	// refuses a round that already has games, so this can't quietly discard a
	// board an admin has adjusted by hand.
	const generatePairings = useMutation( {
		mutationFn: () =>
			api.post( `rounds/${ currentRoundId }/pairings/generate`, {} ),
		onSuccess: invalidateRound,
	} );

	// Round dates are the only thing here that isn't competition data, so this
	// stays open whatever the round's status — correcting the evening a round was
	// played on is a legitimate fix after the fact.
	const setRoundDate = useMutation( {
		mutationFn: ( date ) => api.patch( `rounds/${ currentRoundId }`, { date } ),
		onSuccess: () => {
			invalidateRound();
			queryClient.invalidateQueries( { queryKey: roundsKey } );
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
		mutationFn: ( { status } ) =>
			api.patch( `rounds/${ currentRoundId }/status`, { status } ),
		onSuccess: () => {
			setConfirmAdvance( false );
			setConfirmReopen( false );
			invalidateRound();
			queryClient.invalidateQueries( { queryKey: roundsKey } );
			queryClient.invalidateQueries( {
				queryKey: keys.season( season.id ),
			} );
			// Completing a round rewrites its snapshot; refresh any entering-round
			// scores that read it (prefix covers all ['standings', …] keys).
			queryClient.invalidateQueries( { queryKey: keys.standings() } );
		},
	} );

	// Assign a bye or clear it (no type) for one player. The pairing bye means
	// "present but unpaired"; other byes (personal, club duty) are absences.
	const setAttendance = useMutation( {
		mutationFn: ( { seasonPlayerId, byeType } ) => {
			const entry = byeType
				? {
						season_player_id: seasonPlayerId,
						status:
							byeType === 'pairing_bye' ? 'present' : 'absent',
						bye_type: byeType,
				  }
				: { season_player_id: seasonPlayerId, status: 'present' };
			return api.put( `rounds/${ currentRoundId }/attendance`, {
				attendance: [ entry ],
			} );
		},
		onSuccess: invalidateRound,
	} );

	// Bulk-assign a bye to every still-unpaired player in one request (the "move
	// remaining here" shortcut on each bye box).
	const moveRemainingToBye = useMutation( {
		mutationFn: ( { ids, byeType } ) =>
			api.put( `rounds/${ currentRoundId }/attendance`, {
				attendance: ids.map( ( id ) => ( {
					season_player_id: id,
					status: 'absent',
					bye_type: byeType,
				} ) ),
			} ),
		onSuccess: invalidateRound,
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

	// Players sitting out this round: season_player_id → bye type key.
	const byeOf = useMemo( () => {
		const map = {};
		for ( const a of roundData?.attendance ?? [] ) {
			if ( a.bye_type ) {
				map[ a.season_player_id ] = a.bye_type;
			}
		}
		return map;
	}, [ roundData ] );
	const byeLabel = ( key ) =>
		key === 'pairing_bye'
			? 'Pairing bye'
			: byeTypes.find( ( b ) => b.key === key )?.label ?? key;

	// Players holding the automatic pairing bye (present but with no opponent).
	const pairingByes = ( roundData?.attendance ?? [] )
		.filter( ( a ) => a.bye_type === 'pairing_bye' )
		.map( ( a ) =>
			players.find( ( p ) => p.season_player_id === a.season_player_id )
		)
		.filter( Boolean );

	// Enrolled players, strongest first — the order you pair a manual board in.
	const pool = useMemo(
		() =>
			[ ...players ].sort( ( a, b ) => ( b.elo || 0 ) - ( a.elo || 0 ) ),
		[ players ]
	);
	// Still to pair: not on a board and not sitting out on a bye.
	const unpaired = pool.filter(
		( p ) =>
			! pairedIds.has( p.season_player_id ) &&
			! byeOf[ p.season_player_id ]
	);

	const dropToSlot = ( slot ) => {
		const d = drag.current;
		drag.current = null;
		setOverSlot( null );
		// Only a pool player forms a new pairing; a board player is unpaired by
		// dropping it back onto the enrolled list.
		if ( ! d || d.from !== 'pool' || ! editable ) {
			return;
		}
		const p = d.player;
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
			return;
		}
		// Only one seat filled and no one else left to oppose them → the odd
		// player out takes the pairing bye instead of a half-made pairing.
		// A player displaced from the slot we're dropping onto returns to the
		// pool, so count them as an available opponent (else a re-drop onto an
		// occupied slot would award a spurious bye and discard the occupant).
		const displaced = slot === 'white' ? builder.white : builder.black;
		const opponentsLeft = unpaired
			.filter( ( u ) => u.season_player_id !== p.season_player_id )
			.concat(
				displaced && displaced.season_player_id !== p.season_player_id
					? [ displaced ]
					: []
			);
		if ( opponentsLeft.length === 0 ) {
			setAttendance.mutate( {
				seasonPlayerId: p.season_player_id,
				byeType: 'pairing_bye',
			} );
			setBuilder( { white: null, black: null } );
		} else {
			setBuilder( next );
		}
	};

	if ( roundsLoading ) {
		return <Notice>Loading…</Notice>;
	}

	// No rounds yet. A full-schedule tournament generates its whole fixture from
	// the roster; anything else starts with one round and is paired by hand.
	if ( ordered.length === 0 ) {
		// Unreachable when locked: a season with no rounds can't be completed.
		if ( locked ) {
			return <Notice>This tournament has no rounds.</Notice>;
		}
		const isFullSchedule = season.cadence === 'full';
		return (
			<div className="space-y-4">
				<p className="text-sm text-ink-3">
					{ isFullSchedule
						? 'No rounds yet. Generate the schedule to lay out every round from the enrolled players.'
						: 'No rounds yet. Create the first round to start pairing.' }
				</p>
				<div className="flex flex-wrap items-center gap-3">
					{ isFullSchedule && (
						<button
							type="button"
							onClick={ () => generateSchedule.mutate() }
							disabled={ generateSchedule.isPending }
							className="rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60"
						>
							{ generateSchedule.isPending
								? 'Generating…'
								: 'Generate all rounds' }
						</button>
					) }
					<button
						type="button"
						onClick={ () => createRound.mutate() }
						disabled={ createRound.isPending }
						className={
							isFullSchedule
								? 'rounded border border-rule px-4 py-2 text-sm text-ink-3 hover:bg-surface hover:text-ink disabled:opacity-40'
								: 'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60'
						}
					>
						{ createRound.isPending
							? 'Creating…'
							: 'Create first round' }
					</button>
				</div>
				{ ( createRound.isError || generateSchedule.isError ) && (
					<p className="text-sm text-loss">
						{ errorMessage(
							generateSchedule.error || createRound.error
						) }
					</p>
				) }
			</div>
		);
	}

	const roundsFull = roundLimit !== null && ordered.length >= roundLimit;
	const genLabel = locked ? null : generateLabel( season.cadence );
	// A generated fixture is the whole round set, so it can't be extended by hand.
	const canAddRound = ! locked && season.cadence !== 'full';
	const nextStatus = round ? nextRoundStatus( round.status ) : null;
	// The admin's cue for closing the season, and the only guard on it — the
	// backend accepts any round once every round that exists is complete, which
	// round 1 of a tournament that creates them one at a time satisfies. A
	// configured round count is the real last round; without one (unlimited
	// Keizer, or a generated fixture) the highest round that exists is the best
	// available answer.
	// Match the backend's board numbering (max existing board + 1) so the
	// builder shows the number the new pairing will actually get.
	const nextBoardNumber =
		games.reduce( ( m, g ) => Math.max( m, g.board || 0 ), 0 ) + 1;

	return (
		<div className="space-y-6">
			{ /* Round bar. The round list is its own flex column so a long
			     schedule wraps within it instead of pushing the round actions
			     onto a line of their own. */ }
			<div className="flex flex-wrap items-start justify-between gap-3 border-b border-rule pb-4">
				<div className="flex min-w-0 flex-1 flex-wrap items-center gap-1">
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

					{ roundsFull && (
						<span className="ml-2 text-sm text-muted">
							All { roundLimit } rounds created
						</span>
					) }
					{ ! roundsFull && canAddRound && (
						<button
							type="button"
							onClick={ () => createRound.mutate() }
							disabled={ createRound.isPending }
							className="ml-1 rounded border border-rule px-3 py-1.5 text-sm text-ink-3 hover:bg-surface hover:text-ink disabled:opacity-40"
						>
							{ createRound.isPending ? 'Adding…' : '+ New round' }
						</button>
					) }
				</div>

				<div className="flex shrink-0 items-center gap-3">
					{ genLabel && season.cadence === 'full' && (
						<button
							type="button"
							onClick={ () => setConfirmGenerate( true ) }
							disabled={ generateSchedule.isPending }
							className="rounded border border-rule px-3 py-1.5 text-sm text-ink-3 hover:bg-surface hover:text-ink disabled:opacity-40"
						>
							{ generateSchedule.isPending
								? 'Generating…'
								: genLabel }
						</button>
					) }
					{ genLabel && season.cadence !== 'full' && (
						<button
							type="button"
							onClick={ () => generatePairings.mutate() }
							disabled={
								generatePairings.isPending ||
								! editable ||
								games.length > 0 ||
								! season.generates_pairings ||
								blockingRound !== null
							}
							title={
								! season.generates_pairings
									? 'Automatic pairing isn’t available for this system yet — build the board by hand below.'
									: games.length > 0
										? 'This round already has pairings. Remove them to generate again.'
										: blockingRound !== null
											? `Complete round ${ blockingRound } first — the board is built from the standings, and those are written when a round is completed. You can still pair this round by hand below.`
											: undefined
							}
							className="rounded border border-rule px-3 py-1.5 text-sm text-ink-3 hover:bg-surface hover:text-ink disabled:opacity-40"
						>
							{ generatePairings.isPending
								? 'Pairing…'
								: genLabel }
						</button>
					) }
					<span className="text-xs uppercase tracking-wide text-muted">
						{ ROUND_STATUS_LABELS[ round?.status ] ??
							round?.status }
					</span>
					{ nextStatus && (
						<button
							type="button"
							onClick={ () => setConfirmAdvance( true ) }
							disabled={ setStatus.isPending }
							className="rounded bg-ink px-3 py-1.5 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60"
						>
							{ ROUND_ADVANCE_LABELS[ nextStatus ] }
						</button>
					) }
					{ /* The status flow is forward-only, so without this a wrong
					     result in a completed round could never be corrected. */ }
					{ round?.status === 'complete' && ! locked && (
						<button
							type="button"
							onClick={ () => setConfirmReopen( true ) }
							disabled={ setStatus.isPending }
							className="rounded border border-rule px-3 py-1.5 text-sm text-ink-3 hover:text-ink disabled:opacity-60"
						>
							Reopen
						</button>
					) }
				</div>
			</div>

			{ round && (
				<div className="flex flex-wrap items-center gap-3">
					<label className="flex items-center gap-2">
						<span className="text-xs uppercase tracking-wide text-muted">
							Date
						</span>
						{ /* Written on blur, not per keystroke: editing one
						     segment of a filled date input changes the whole
						     value, so typing December over August would save
						     January on the way, and emptying a segment makes
						     the value '' — which clears the stored date. */ }
						<input
							type="date"
							disabled={ locked }
							value={ dateDraft ?? round.date ?? '' }
							onChange={ ( e ) => setDateDraft( e.target.value ) }
							onBlur={ ( e ) => {
								if ( e.target.value !== ( round.date ?? '' ) ) {
									setRoundDate.mutate( e.target.value );
								}
							} }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' ) {
									e.currentTarget.blur();
								}
							} }
							className="rounded border border-rule bg-paper px-2 py-1 text-sm text-ink disabled:opacity-60"
						/>
					</label>
					{ ( dateDraft ?? round.date ) && ! locked && (
						<button
							type="button"
							onClick={ () => {
								setDateDraft( '' );
								setRoundDate.mutate( '' );
							} }
							disabled={ setRoundDate.isPending }
							className="text-xs text-ink-3 hover:text-ink disabled:opacity-40"
						>
							Clear
						</button>
					) }
					{ setRoundDate.isError && (
						<span className="text-sm text-loss">
							{ errorMessage( setRoundDate.error ) }
						</span>
					) }
				</div>
			) }

			{ generatePairings.isError && (
				<Notice>{ errorMessage( generatePairings.error ) }</Notice>
			) }

			{ ! season.generates_pairings && season.cadence !== 'manual' && (
				<Notice>
					That engine isn’t built yet — pairings can be entered by
					hand
				</Notice>
			) }

			{ ! editable && round && (
				<Notice>
					Round { round.round_number } is{ ' ' }
					{ ROUND_STATUS_LABELS[ round.status ]?.toLowerCase() }, so
					its pairings are locked.
					{ resultsOpen ? ' Results can still be entered.' : '' }
				</Notice>
			) }

			{ /* Boards */ }
			<div className="space-y-2">
				{ games.length === 0 && ! builder.white && ! builder.black && (
					<p className="text-sm text-muted">
						No pairings yet. Drag players from the list below into
						the White and Black slots.
					</p>
				) }

				{ games.map( ( g ) => (
					<Board
						key={ g.id }
						game={ g }
						editable={ editable }
						resultsVisible={ resultsVisible }
						resultsOpen={ resultsOpen }
						scoreOf={ scoreOf }
						onResult={ ( result ) =>
							setResult.mutate( {
								id: g.id,
								result: g.result === result ? null : result,
							} )
						}
						onSwap={ () => swapGame.mutate( g ) }
						onRemove={ () => deleteGame.mutate( g.id ) }
						onDragOut={ ( player ) => {
							drag.current = {
								from: 'board',
								player,
								gameId: g.id,
							};
						} }
					/>
				) ) }

				{ pairingByes.map( ( p ) => (
					<ByeBoard
						key={ p.season_player_id }
						player={ p }
						scoreOf={ scoreOf }
						editable={ editable }
						onRemove={ () =>
							setAttendance.mutate( {
								seasonPlayerId: p.season_player_id,
								byeType: null,
							} )
						}
					/>
				) ) }

				{ editable &&
					( unpaired.length > 0 ||
						builder.white ||
						builder.black ) && (
						<BuilderBoard
							board={ nextBoardNumber }
							builder={ builder }
							overSlot={ overSlot }
							onOver={ setOverSlot }
							onDrop={ dropToSlot }
							onClear={ ( slot ) =>
								setBuilder( ( prev ) => ( {
									...prev,
									[ slot ]: null,
								} ) )
							}
						/>
					) }
			</div>

			{ ( createGame.isError ||
				setResult.isError ||
				deleteGame.isError ) && (
				<p className="text-sm text-loss">
					{ errorMessage(
						createGame.error || setResult.error || deleteGame.error
					) }
				</p>
			) }

			{ /* Enrolled players pool — also a drop target: dropping a board
			     player here dissolves that pairing; dropping a bye player here
			     clears their bye. */ }
			<div
				className={
					'rounded border-t pt-4 ' +
					( poolOver
						? 'border-accent bg-accent-soft/40'
						: 'border-rule' )
				}
				onDragOver={ ( e ) => {
					const from = drag.current?.from;
					if ( editable && ( from === 'board' || from === 'bye' ) ) {
						e.preventDefault();
						setPoolOver( true );
					}
				} }
				onDragLeave={ () => setPoolOver( false ) }
				onDrop={ () => {
					const d = drag.current;
					drag.current = null;
					setPoolOver( false );
					if ( ! editable || ! d ) {
						return;
					}
					if ( d.from === 'board' ) {
						deleteGame.mutate( d.gameId );
					} else if ( d.from === 'bye' ) {
						setAttendance.mutate( {
							seasonPlayerId: d.player.season_player_id,
							byeType: null,
						} );
					}
				} }
			>
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
						const onBoard = pairedIds.has( p.season_player_id );
						const bye = byeOf[ p.season_player_id ];
						const placed = onBoard || !! bye;
						return (
							<li
								key={ p.season_player_id }
								draggable={ editable && ! placed }
								onDragStart={ () => {
									drag.current = { from: 'pool', player: p };
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
									{ onBoard && (
										<span className="text-[11px] text-muted">
											board{ ' ' }
											{ boardOf[ p.season_player_id ] }
										</span>
									) }
									{ ! onBoard && bye && (
										<span className="text-[11px] text-muted">
											{ byeLabel( bye ) }
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

				{ byeTypes.length > 0 && (
					<div className="mt-4 border-t border-rule-soft pt-4">
						<h4 className="mb-2 text-xs font-medium uppercase tracking-wide text-muted">
							Byes
						</h4>
						<p className="mb-3 text-xs text-muted">
							Drag a player into a bye to sit them out this round;
							drag them back to the list to clear it. The odd
							player out is given the pairing bye automatically.
						</p>
						<div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
							{ byeTypes.map( ( b ) => (
								<ByeBox
									key={ b.key }
									type={ b }
									players={ pool.filter(
										( p ) =>
											byeOf[ p.season_player_id ] ===
											b.key
									) }
									editable={ editable }
									remaining={ unpaired.length }
									onMoveRemaining={ () =>
										moveRemainingToBye.mutate( {
											ids: unpaired.map(
												( p ) => p.season_player_id
											),
											byeType: b.key,
										} )
									}
									isOver={ byeOver === b.key }
									onOver={ () => setByeOver( b.key ) }
									onLeave={ () => setByeOver( null ) }
									onDrop={ () => {
										const d = drag.current;
										drag.current = null;
										setByeOver( null );
										if (
											! editable ||
											! d ||
											d.from === 'board'
										) {
											return;
										}
										setAttendance.mutate( {
											seasonPlayerId:
												d.player.season_player_id,
											byeType: b.key,
										} );
									} }
									onDragOut={ ( player ) => {
										drag.current = {
											from: 'bye',
											player,
											byeType: b.key,
										};
									} }
								/>
							) ) }
						</div>
						{ setAttendance.isError && (
							<p className="mt-2 text-sm text-loss">
								{ errorMessage( setAttendance.error ) }
							</p>
						) }
					</div>
				) }
			</div>

			{ confirmAdvance && nextStatus && (
				<ConfirmModal
					title={ ROUND_ADVANCE_LABELS[ nextStatus ] }
					confirmLabel={
						setStatus.isPending
							? 'Working…'
							: ROUND_ADVANCE_LABELS[ nextStatus ]
					}
					danger={ nextStatus === 'complete' }
					busy={ setStatus.isPending }
					onCancel={ () => {
						setConfirmAdvance( false );
					} }
					onConfirm={ () =>
						setStatus.mutate( { status: nextStatus } )
					}
				>
					{ nextStatus === 'published' &&
						'Publishing makes this round’s pairings visible. You can still adjust them afterwards.' }
					{ nextStatus === 'finalised' &&
						'Finalising locks the pairings so they can’t be changed. Results can still be entered.' }
					{ nextStatus === 'complete' &&
						'Completing the round freezes its standings snapshot.' }
					{ setStatus.isError && (
						<span className="mt-2 block text-loss">
							{ errorMessage( setStatus.error ) }
						</span>
					) }
				</ConfirmModal>
			) }

			{ confirmGenerate && (
				<ConfirmModal
					title="Generate tournament pairings"
					confirmLabel={
						generateSchedule.isPending
							? 'Generating…'
							: 'Generate all rounds'
					}
					danger
					busy={ generateSchedule.isPending }
					onCancel={ () => setConfirmGenerate( false ) }
					onConfirm={ () => generateSchedule.mutate() }
				>
					This lays out every round from the { players.length } enrolled
					players and replaces the rounds already here. Only possible
					while every round is still a draft — publish one and the
					schedule is fixed. Enrolling a player afterwards won’t change
					it.
					{ generateSchedule.isError && (
						<span className="mt-2 block text-loss">
							{ errorMessage( generateSchedule.error ) }
						</span>
					) }
				</ConfirmModal>
			) }

			{ confirmReopen && (
				<ConfirmModal
					title="Reopen this round"
					confirmLabel={
						setStatus.isPending ? 'Working…' : 'Reopen round'
					}
					busy={ setStatus.isPending }
					onCancel={ () => setConfirmReopen( false ) }
					onConfirm={ () => setStatus.mutate( { status: 'finalised' } ) }
				>
					Reopening lets you correct a result. The published standings
					stay as they are until you complete the round again — which
					recalculates this round and every later completed round.
					{ setStatus.isError && (
						<span className="mt-2 block text-loss">
							{ errorMessage( setStatus.error ) }
						</span>
					) }
				</ConfirmModal>
			) }
		</div>
	);
}

// Tournament score for display: whole numbers stay whole, fractions (½-point
// steps, Keizer decimals) trim to at most two places.
function formatScore( value ) {
	const n = Number( value ) || 0;
	return Number.isInteger( n )
		? String( n )
		: String( Math.round( n * 100 ) / 100 );
}

// A persisted pairing: White vs Black, with a result control, colour swap and
// remove. Each player can be dragged out (onto the pool) to unpair. Locked
// (read-only result view) once the round isn't editable.
function Board( {
	game,
	editable,
	resultsVisible,
	resultsOpen,
	scoreOf,
	onResult,
	onSwap,
	onRemove,
	onDragOut,
} ) {
	// side 'white' reads name (score); side 'black' mirrors to (score) name, so
	// both scores sit next to the central "vs".
	const seat = ( player, side ) => {
		const score = player
			? formatScore( scoreOf[ player.season_player_id ] ?? 0 )
			: null;
		const scoreEl = player && (
			<span
				className={
					'num font-mono text-xs text-muted ' +
					( side === 'white' ? 'ml-1' : 'mr-1' )
				}
			>
				({ score })
			</span>
		);
		return (
			<span
				draggable={ editable && !! player }
				onDragStart={ () => onDragOut( player ) }
				className={
					'truncate text-sm text-ink ' +
					( side === 'white' ? 'text-right' : 'text-left' ) +
					( editable && player ? ' cursor-grab' : '' )
				}
			>
				{ side === 'black' && scoreEl }
				{ player?.name ?? '—' }
				{ side === 'white' && scoreEl }
			</span>
		);
	};
	return (
		<div className="flex items-center gap-3 rounded border border-rule bg-surface px-3 py-2">
			<span className="num w-8 shrink-0 font-mono text-xs text-muted">
				{ game.board }
			</span>
			<div className="grid flex-1 grid-cols-[1fr_auto_1fr] items-center gap-2">
				{ seat( game.white, 'white' ) }
				<span className="text-xs text-muted">vs</span>
				{ seat( game.black, 'black' ) }
			</div>
			{ /* Same reading as the viewer's CAT column, so a board that reaches
			     across categories is as visible while it's being built as it is
			     once published. Empty for a season without categories. */ }
			<span className="w-16 shrink-0 text-right text-xs text-ink-3">
				{ categoryLabel( game.white, game.black ) }
			</span>
			{ resultsVisible && (
				<ResultControl
					result={ game.result }
					disabled={ ! resultsOpen }
					onResult={ onResult }
				/>
			) }
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

// The odd player out: present but with no opponent, so they take the pairing
// bye. Rendered as a board (without a number) so it reads as part of the round.
function ByeBoard( { player, scoreOf, editable, onRemove } ) {
	const score = formatScore( scoreOf[ player.season_player_id ] ?? 0 );
	return (
		<div className="flex items-center gap-3 rounded border border-dashed border-rule bg-surface px-3 py-2">
			<span className="num w-8 shrink-0 text-center font-mono text-xs text-muted">
				–
			</span>
			<div className="grid flex-1 grid-cols-[1fr_auto_1fr] items-center gap-2">
				<span className="truncate text-right text-sm text-ink">
					{ player.name }
					<span className="num ml-1 font-mono text-xs text-muted">
						({ score })
					</span>
				</span>
				<span className="text-xs text-muted">bye</span>
				<span className="truncate text-left text-sm italic text-muted">
					Pairing bye
				</span>
			</div>
			{ editable && (
				<div className="flex shrink-0 items-center gap-1">
					<span className="px-1.5 py-1 text-xs" aria-hidden="true">
						{ ' ' }
					</span>
					<button
						type="button"
						onClick={ onRemove }
						title="Remove bye"
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
					player={ builder.white }
					isOver={ overSlot === 'white' }
					onOver={ onOver }
					onDrop={ onDrop }
					onClear={ onClear }
				/>
				<span className="text-xs text-muted">vs</span>
				<Slot
					side="black"
					player={ builder.black }
					isOver={ overSlot === 'black' }
					onOver={ onOver }
					onDrop={ onDrop }
					onClear={ onClear }
				/>
			</div>
			{ /* Reserve the same right-side space the filled boards use for the
			     swap/remove controls, so the "vs" lines up with them. */ }
			<div
				className="invisible flex shrink-0 items-center gap-1"
				aria-hidden="true"
			>
				<span className="px-1.5 py-1 text-xs">⇄</span>
				<span className="px-1.5 py-1 text-xs">×</span>
			</div>
		</div>
	);
}

function Slot( { side, player, isOver, onOver, onDrop, onClear } ) {
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
				<span className="flex-1">{ side } player</span>
			) }
		</div>
	);
}

// One droppable bye box (e.g. "Club duty"). Players dropped here sit out this
// round; each row can be dragged back to the pool to clear the bye.
function ByeBox( {
	type,
	players,
	editable,
	remaining,
	onMoveRemaining,
	isOver,
	onOver,
	onLeave,
	onDrop,
	onDragOut,
} ) {
	return (
		<section
			onDragOver={ ( e ) => {
				if ( editable ) {
					e.preventDefault();
					onOver();
				}
			} }
			onDragLeave={ onLeave }
			onDrop={ onDrop }
			className={
				'rounded border bg-surface ' +
				( isOver ? 'border-accent ring-1 ring-accent' : 'border-rule' )
			}
		>
			<div className="flex items-center justify-between border-b border-rule-soft px-3 py-2">
				<span className="text-sm font-medium text-ink">
					{ type.label }
				</span>
				<span className="num font-mono text-xs text-muted">
					{ Number( type.points ) } pt · { players.length }
				</span>
			</div>
			{ editable && remaining > 0 && (
				<button
					type="button"
					onClick={ onMoveRemaining }
					className="w-full border-b border-rule-soft px-3 py-1.5 text-left text-xs text-accent-2 hover:bg-paper"
				>
					Move remaining { remaining } here
				</button>
			) }
			<ul className="min-h-16 space-y-1 p-1.5">
				{ players.length === 0 ? (
					<li className="px-2 py-3 text-center text-xs text-muted">
						Drop players here
					</li>
				) : (
					players.map( ( p ) => (
						<li
							key={ p.season_player_id }
							draggable={ editable }
							onDragStart={ () => onDragOut( p ) }
							className={
								'flex items-center justify-between rounded px-2 py-1 text-sm text-ink-3 ' +
								( editable ? 'cursor-grab hover:bg-paper' : '' )
							}
						>
							<span className="truncate">{ p.name }</span>
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
