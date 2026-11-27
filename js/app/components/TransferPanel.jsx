import { useEffect, useRef } from '@wordpress/element';
import { SearchInput } from './ui';

// One column of a two-panel transfer list. Rows are click-to-toggle selectable
// and draggable; the whole panel is a drop target. Shift-click gives every
// visible row from the last one clicked to this one that row's state.
export function TransferPanel( {
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

	// An emptied selection (moved, or cleared by the other panel) drops the anchor.
	useEffect( () => {
		if ( selected.size === 0 ) {
			anchor.current = null;
		}
	}, [ selected ] );

	const click = ( e, id ) => {
		const ids = rows.map( rowId );
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
					rows.map( ( p ) => {
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
