import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { SearchInput } from './ui';

// One column of a two-panel transfer list. Rows are click-to-toggle selectable
// and draggable; the whole panel is a drop target. Shift-click gives every
// visible row from the last one clicked to this one that row's state. Pass
// `rowRating` for Name / Rating headers that sort the panel, by name first.
export function TransferPanel( {
	title,
	side,
	rows,
	rowId,
	rowLabel,
	rowMeta,
	rowRating,
	selected,
	search,
	onSearch,
	onToggle,
	onSelectRange,
	onDragStart,
	isOver,
	onDragOver,
	onDragLeave,
	onDrop,
	locked = false,
	empty,
	action,
} ) {
	const anchor = useRef( null );
	const [ sort, setSort ] = useState( { key: 'name', dir: 'asc' } );

	const sorted = useMemo( () => {
		if ( ! rowRating ) {
			return rows;
		}
		const byName = ( a, b ) =>
			String( rowLabel( a ) ?? '' ).localeCompare(
				String( rowLabel( b ) ?? '' )
			);
		const cmp =
			sort.key === 'rating'
				? ( a, b ) =>
						( rowRating( a ) || 0 ) - ( rowRating( b ) || 0 ) ||
						byName( a, b )
				: byName;
		const sign = sort.dir === 'asc' ? 1 : -1;
		return [ ...rows ].sort( ( a, b ) => sign * cmp( a, b ) );
	}, [ rows, rowLabel, rowRating, sort ] );

	// A new column starts ascending for names and highest-first for ratings.
	const sortBy = ( key ) =>
		setSort( ( prev ) => {
			if ( prev.key === key ) {
				return { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' };
			}
			return { key, dir: key === 'rating' ? 'desc' : 'asc' };
		} );

	// An emptied selection (moved, or cleared by the other panel) drops the anchor.
	useEffect( () => {
		if ( selected.size === 0 ) {
			anchor.current = null;
		}
	}, [ selected ] );

	const click = ( e, id ) => {
		const ids = sorted.map( rowId );
		const from = ids.indexOf( anchor.current );
		if ( e.shiftKey && from !== -1 ) {
			const to = ids.indexOf( id );
			onSelectRange(
				side,
				ids.slice( Math.min( from, to ), Math.max( from, to ) + 1 ),
				selected.has( anchor.current )
			);
		} else {
			onToggle( side, id );
		}
		anchor.current = id;
	};

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
			{ rowRating && (
				<div className="flex justify-between px-3.5 pb-1">
					<SortButton
						label="Name"
						col="name"
						sort={ sort }
						onSort={ sortBy }
					/>
					<SortButton
						label="Rating"
						col="rating"
						sort={ sort }
						onSort={ sortBy }
					/>
				</div>
			) }
			<ul
				onDragOver={
					locked
						? undefined
						: ( e ) => {
								e.preventDefault();
								onDragOver();
							}
				}
				onDragLeave={ locked ? undefined : onDragLeave }
				onDrop={ locked ? undefined : onDrop }
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
					sorted.map( ( p ) => {
						const id = rowId( p );
						const isSel = selected.has( id );
						const meta = rowMeta( p );
						return (
							// eslint-disable-next-line jsx-a11y/click-events-have-key-events -- mouse-only by decision; nobody drives this list from the keyboard.
							<li
								key={ id }
								draggable={ ! locked }
								onDragStart={
									locked
										? undefined
										: () => onDragStart( side, id )
								}
								onClick={
									locked ? undefined : ( e ) => click( e, id )
								}
								className={
									// select-none: shift-click would otherwise also select the text in between.
									'flex select-none items-center justify-between rounded px-2 py-1.5 text-sm ' +
									( locked
										? 'text-ink-3'
										: 'cursor-pointer ' +
											( isSel
												? 'bg-accent-soft text-ink'
												: 'text-ink-3 hover:bg-paper' ) )
								}
							>
								<span className="truncate">
									{ rowLabel( p ) }
								</span>
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

function SortButton( { label, col, sort, onSort } ) {
	const active = sort.key === col;
	return (
		<button
			type="button"
			onClick={ () => onSort( col ) }
			className="flex items-center gap-1 text-xs uppercase tracking-wide text-muted hover:text-ink"
		>
			{ label }
			{ active && <span>{ sort.dir === 'asc' ? '▲' : '▼' }</span> }
		</button>
	);
}
