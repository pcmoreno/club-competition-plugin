import { useEffect } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';

// Global tournament switcher — scopes every member/public view at once.
// Normally lists active seasons (multiple can be active: a league plus side
// tournaments). In the off-season, when nothing is active, it falls back to
// completed seasons (most recent first) so the public viewer isn't empty —
// which doubles as past-season browsing.
//
// Reads/writes the selected season id via the `value`/`onChange` props so the
// App owns the single source of truth (and can persist it later).
export function TournamentSwitcher( { value, onChange } ) {
	// GET /seasons returns all seasons (no status filter server-side yet);
	// narrow here: active ones while a competition is running, else the
	// completed ones (newest first) so the off-season still shows a season.
	const { data: all = [], isLoading } = useQuery( {
		queryKey: [ 'seasons' ],
		queryFn: () => api.get( 'seasons' ),
	} );
	const active = all.filter( ( s ) => s.status === 'active' );
	const completed = all
		.filter( ( s ) => s.status === 'completed' )
		.sort( ( a, b ) =>
			( b.start_date ?? '' ).localeCompare( a.start_date ?? '' )
		);
	const seasons = active.length > 0 ? active : completed;

	// Establish a selected season as soon as the list loads, so every view is
	// scoped from first paint. Covers both the single-season (no <select>) and
	// multi-season cases, and keeps the controlled <select> value always
	// matching an <option>.
	useEffect( () => {
		if ( value === null && seasons.length > 0 ) {
			onChange( seasons[ 0 ].id );
		}
	}, [ value, seasons, onChange ] );

	// Hide the control entirely when there's nothing (or only one thing) to pick.
	if ( isLoading || seasons.length <= 1 ) {
		const only = seasons[ 0 ];
		return only ? (
			<span className="text-sm font-medium text-ink-2">
				{ only.name }
			</span>
		) : null;
	}

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
				{ seasons.map( ( s ) => (
					<option key={ s.id } value={ s.id }>
						{ s.name }
					</option>
				) ) }
			</select>
		</div>
	);
}
