import { useState, useMemo, useRef } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { Notice, formatDate } from '../components/ui';
import { TransferPanel } from '../components/TransferPanel';
import { errorMessage } from './tournamentShared';
import { keys } from '../api/keys';

// ADMIN. Absences tab: a two-panel transfer list splitting the roster into
// players expected every week and players sitting the tournament out, plus the
// absences already recorded for the round about to be played.
//
// Moving a player to Default absent doesn't touch rounds that already exist —
// the personal bye is written when a round is created.
export function TournamentAbsencesTab( { season, locked = false } ) {
	const queryClient = useQueryClient();

	const [ selPresent, setSelPresent ] = useState( () => new Set() );
	const [ selAbsent, setSelAbsent ] = useState( () => new Set() );
	const [ presentSearch, setPresentSearch ] = useState( '' );
	const [ absentSearch, setAbsentSearch ] = useState( '' );
	const [ dropTarget, setDropTarget ] = useState( null );
	const drag = useRef( null );

	const { data, isLoading, isError } = useQuery( {
		queryKey: keys.seasonAbsences( season.id ),
		queryFn: () => api.get( `seasons/${ season.id }/absences` ),
	} );

	const { data: settingsData } = useQuery( {
		queryKey: keys.seasonSettings( season.id ),
		queryFn: () => api.get( `seasons/${ season.id }/settings` ),
	} );

	const byeLabel = ( key ) =>
		( settingsData?.scoring?.values?.byeTypes ?? [] ).find(
			( b ) => b.key === key
		)?.label ??
		key ??
		'Absent';

	const split = useMemo( () => {
		const rows = data?.enrolments ?? [];
		const byName = ( a, b ) =>
			( a.name ?? '' ).localeCompare( b.name ?? '' );
		const match = ( q ) => ( p ) =>
			! q || ( p.name ?? '' ).toLowerCase().includes( q );

		return {
			present: rows
				.filter( ( p ) => ! p.default_absent )
				.filter( match( presentSearch.trim().toLowerCase() ) )
				.sort( byName ),
			absent: rows
				.filter( ( p ) => p.default_absent )
				.filter( match( absentSearch.trim().toLowerCase() ) )
				.sort( byName ),
			presentCount: rows.filter( ( p ) => ! p.default_absent ).length,
			absentCount: rows.filter( ( p ) => p.default_absent ).length,
		};
	}, [ data, presentSearch, absentSearch ] );

	const move = useMutation( {
		mutationFn: ( { ids, defaultAbsent } ) =>
			api.patch( `seasons/${ season.id }/absences`, {
				player_ids: ids,
				default_absent: defaultAbsent,
			} ),
		onSuccess: () => {
			setSelPresent( new Set() );
			setSelAbsent( new Set() );
		},
		onSettled: () =>
			queryClient.invalidateQueries( {
				queryKey: keys.seasonAbsences( season.id ),
			} ),
	} );

	const doMove = ( ids, defaultAbsent ) => {
		if ( ! locked && ids.length > 0 ) {
			move.mutate( { ids, defaultAbsent } );
		}
	};

	// Selecting in one panel clears the other, so the centre button is
	// unambiguous.
	const toggle = ( side, id ) => {
		if ( side === 'present' ) {
			setSelAbsent( new Set() );
			setSelPresent( ( prev ) => {
				const next = new Set( prev );
				next.has( id ) ? next.delete( id ) : next.add( id );
				return next;
			} );
		} else {
			setSelPresent( new Set() );
			setSelAbsent( ( prev ) => {
				const next = new Set( prev );
				next.has( id ) ? next.delete( id ) : next.add( id );
				return next;
			} );
		}
	};

	const onDragStart = ( side, id ) => {
		const sel = side === 'present' ? selPresent : selAbsent;
		const ids = sel.has( id ) && sel.size > 0 ? [ ...sel ] : [ id ];
		drag.current = { from: side, ids };
	};

	const onDropTo = ( target ) => {
		const d = drag.current;
		drag.current = null;
		setDropTarget( null );
		if ( ! d || d.from === target ) {
			return;
		}
		doMove( d.ids, target === 'absent' );
	};

	const absentMode = selPresent.size > 0;
	const presentMode = ! absentMode && selAbsent.size > 0;

	if ( isLoading ) {
		return <Notice>Loading…</Notice>;
	}
	if ( isError ) {
		return <Notice>Couldn’t load this tournament’s absences.</Notice>;
	}

	const rowMeta = ( p ) => {
		const cat = p.category ? ` (${ p.category })` : '';
		return `${ p.elo || '' }${ cat }`.trim();
	};

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-[1fr_auto_1fr] items-stretch gap-3">
				<TransferPanel
					title={ `Default present (${ split.presentCount })` }
					side="present"
					rows={ split.present }
					rowId={ ( p ) => p.player_id }
					rowLabel={ ( p ) => p.name }
					rowMeta={ rowMeta }
					selected={ selPresent }
					search={ presentSearch }
					onSearch={ setPresentSearch }
					onToggle={ toggle }
					onDragStart={ onDragStart }
					isOver={ dropTarget === 'present' }
					onDragOver={ () => setDropTarget( 'present' ) }
					onDragLeave={ () => setDropTarget( null ) }
					onDrop={ () => onDropTo( 'present' ) }
					locked={ locked }
					empty="Nobody is expected every week."
				/>

				{ locked ? (
					<div />
				) : (
					<div className="flex justify-center">
						<button
							type="button"
							onClick={ () =>
								absentMode
									? doMove( [ ...selPresent ], true )
									: doMove( [ ...selAbsent ], false )
							}
							disabled={
								( ! absentMode && ! presentMode ) ||
								move.isPending
							}
							className="sticky top-28 mt-24 self-start whitespace-nowrap rounded bg-ink px-3 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-40"
						>
							{ presentMode ? '← Present' : 'Absent →' }
						</button>
					</div>
				) }

				<TransferPanel
					title={ `Default absent (${ split.absentCount })` }
					side="absent"
					rows={ split.absent }
					rowId={ ( p ) => p.player_id }
					rowLabel={ ( p ) => p.name }
					rowMeta={ rowMeta }
					selected={ selAbsent }
					search={ absentSearch }
					onSearch={ setAbsentSearch }
					onToggle={ toggle }
					onDragStart={ onDragStart }
					isOver={ dropTarget === 'absent' }
					onDragOver={ () => setDropTarget( 'absent' ) }
					onDragLeave={ () => setDropTarget( null ) }
					onDrop={ () => onDropTo( 'absent' ) }
					locked={ locked }
					empty="Nobody is sitting this tournament out."
				/>
			</div>

			{ move.error && (
				<p className="text-sm text-loss">
					{ errorMessage( move.error ) }
				</p>
			) }

			<DeclaredAbsences
				round={ data?.round ?? null }
				declared={ data?.declared ?? [] }
				byeLabel={ byeLabel }
			/>
		</div>
	);
}

// Absences recorded for the round about to be played. Standing absences are the
// panels' subject and never appear here; so do declarations made after the
// pairings go out, which are emailed to the tournament's contacts instead of
// being recorded.
function DeclaredAbsences( { round, declared, byeLabel } ) {
	if ( round === null ) {
		return (
			<section>
				<h3 className="mb-2 text-sm font-medium text-ink">
					Declared absences
				</h3>
				<p className="text-sm text-muted">
					No round is waiting to be played.
				</p>
			</section>
		);
	}

	const date = formatDate( round.date );

	return (
		<section>
			<h3 className="mb-2 text-sm font-medium text-ink">
				Declared absences — round { round.number }
				{ date ? ` · ${ date }` : '' }
			</h3>
			{ declared.length === 0 ? (
				<p className="text-sm text-muted">
					Nobody has been recorded absent for this round.
				</p>
			) : (
				<ul className="divide-y divide-rule rounded border border-rule bg-surface">
					{ declared.map( ( row ) => (
						<li
							key={ row.season_player_id }
							className="flex items-center justify-between px-3 py-2 text-sm"
						>
							<span className="truncate text-ink-3">
								{ row.name }
							</span>
							<span className="ml-2 shrink-0 text-xs text-muted">
								{ byeLabel( row.bye_type ) }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}
