import { SearchInput } from './ui';

// One column of a two-panel transfer list. Rows are click-to-toggle selectable
// and draggable; the whole panel is a drop target.
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
	onDragStart,
	isOver,
	onDragOver,
	onDragLeave,
	onDrop,
	locked = false,
	empty,
	action,
} ) {
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
							<li
								key={ id }
								draggable={ ! locked }
								onDragStart={
									locked
										? undefined
										: () => onDragStart( side, id )
								}
								onClick={
									locked
										? undefined
										: () => onToggle( side, id )
								}
								className={
									'flex items-center justify-between rounded px-2 py-1.5 text-sm ' +
									( locked
										? 'text-ink-3'
										: 'cursor-pointer ' +
										  ( isSel
												? 'bg-accent-soft text-ink'
												: 'text-ink-3 hover:bg-paper' ) )
								}
							>
								<span className="truncate">{ rowLabel( p ) }</span>
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
