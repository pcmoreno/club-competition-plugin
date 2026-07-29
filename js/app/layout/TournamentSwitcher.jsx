import { useEffect, useMemo } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { keys } from '../api/keys';

// Global tournament switcher — scopes every member/public view at once.
// Lists the active seasons (multiple can be active: a league plus side
// tournaments) followed by the completed ones, newest first.
//
// Completed seasons are listed *alongside* the active ones rather than as an
// off-season fallback: a finished tournament stays worth browsing, and dropping
// it the moment a new one starts made past results unreachable from the viewer.
//
// Reads/writes the selected season id via the `value`/`onChange` props so the
// App owns the single source of truth (and can persist it later).
export function TournamentSwitcher( { value, onChange } ) {
	// GET /seasons returns every season (no status filter server-side yet), so
	// narrow here. Seasons still in preparation stay hidden — they have nothing
	// to show yet.
	const { data: all = [], isLoading } = useQuery( {
		queryKey: keys.seasons(),
		queryFn: () => api.get( 'seasons' ),
	} );

	// Memoised because `seasons` is an effect dependency below: rebuilding the
	// array every render would re-run the effect every render.
	const { active, completed, seasons } = useMemo( () => {
		// Active keeps the order the API returned, so the default selection
		// doesn't shift; completed is sorted newest first.
		const nextActive = all.filter( ( s ) => s.status === 'active' );
		const nextCompleted = all
			.filter( ( s ) => s.status === 'completed' )
			.sort( ( a, b ) =>
				( b.start_date ?? '' ).localeCompare( a.start_date ?? '' )
			);

		return {
			active: nextActive,
			completed: nextCompleted,
			// Active first, so seasons[0] below picks a running tournament
			// whenever there is one.
			seasons: [ ...nextActive, ...nextCompleted ],
		};
	}, [ all ] );

	// Establish a selected season as soon as the list loads, so every view is
	// scoped from first paint. Covers both the single-season (no <select>) and
	// multi-season cases, and keeps the controlled <select> value always
	// matching an <option>.
	useEffect( () => {
		if ( value === null && seasons.length > 0 ) {
			onChange( seasons[ 0 ].id );
		}
	}, [ value, seasons, onChange ] );

	// Nothing (or only one thing) to pick: no control needed.
	if ( isLoading || seasons.length <= 1 ) {
		const only = seasons[ 0 ];
		return only ? (
			<span className="text-sm font-medium text-ink-2">
				{ only.name }
			</span>
		) : null;
	}

	const option = ( s ) => (
		<option key={ s.id } value={ s.id }>
			{ s.name }
		</option>
	);

	return (
		<div className="flex items-center gap-2 text-sm text-ink-3">
			<label htmlFor="scs-tournament" className="sr-only">
				Tournament
			</label>
			<select
				id="scs-tournament"
				className="min-w-[15rem] rounded border-rule bg-surface px-2 py-1 text-sm text-ink"
				value={ value ?? '' }
				onChange={ ( e ) => onChange( Number( e.target.value ) ) }
			>
				{ active.length > 0 && (
					<optgroup label="Active">{ active.map( option ) }</optgroup>
				) }
				{ completed.length > 0 && (
					<optgroup label="Completed">
						{ completed.map( option ) }
					</optgroup>
				) }
			</select>
		</div>
	);
}
