import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';

// Global tournament switcher — scopes every member/public view at once.
// Lists active seasons only (multiple can be active: a league plus side
// tournaments). Archive/past-season browsing is a later feature.
//
// Reads/writes the selected season id via the `value`/`onChange` props so the
// App owns the single source of truth (and can persist it later).
export function TournamentSwitcher( { value, onChange } ) {
	// GET /seasons returns all seasons (no status filter server-side yet);
	// narrow to active ones here.
	const { data: all = [], isLoading } = useQuery( {
		queryKey: [ 'seasons' ],
		queryFn: () => api.get( 'seasons' ),
	} );
	const seasons = all.filter( ( s ) => s.status === 'active' );

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
				className="rounded border-rule bg-surface px-2 py-1 text-sm text-ink"
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
