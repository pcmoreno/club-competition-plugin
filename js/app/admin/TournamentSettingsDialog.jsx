import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { Notice } from '../components/ui';

// ADMIN. Tournament engine settings. The form is rendered entirely from the
// field schema served by GET /seasons/{id}/settings, so a new scoring system
// (or a new metric) needs no change here — only its getSettingsFields().
// Scoring is read-only once a round has completed; display settings never lock.

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink disabled:opacity-60';
const inputCls =
	'rounded border border-rule bg-paper px-2 py-1 text-sm text-ink disabled:opacity-60';

function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		// Field-level validation errors come back keyed by path.
		const errors = err.body?.data?.errors || err.body?.errors;
		if ( errors && typeof errors === 'object' ) {
			return Object.entries( errors )
				.map( ( [ key, msg ] ) => `${ key }: ${ msg }` )
				.join( ' · ' );
		}
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

function SectionTitle( { children, hint } ) {
	return (
		<div className="mb-2">
			<h3 className="text-xs font-medium uppercase tracking-[0.08em] text-muted">
				{ children }
			</h3>
			{ hint && <p className="mt-0.5 text-xs text-ink-3">{ hint }</p> }
		</div>
	);
}

// Ordered pick-list: the selected column on the right of Sevilla's dual pane,
// reduced to a single list with add / reorder / remove.
function OrderedMultiSelect( { options, value, onChange, disabled } ) {
	const selected = Array.isArray( value ) ? value : [];
	const labelOf = ( v ) =>
		options.find( ( o ) => o.value === v )?.label ?? v;
	const available = options.filter(
		( o ) => ! selected.includes( o.value ) && o.implemented !== false
	);

	const move = ( index, delta ) => {
		const next = [ ...selected ];
		const target = index + delta;
		if ( target < 0 || target >= next.length ) {
			return;
		}
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		onChange( next );
	};

	return (
		<div>
			{ selected.length === 0 && (
				<p className="mb-2 text-sm text-ink-3">None selected.</p>
			) }
			<ul className="mb-2 flex flex-col gap-1">
				{ selected.map( ( v, i ) => (
					<li
						key={ v }
						className="flex items-center gap-2 rounded border border-rule bg-surface px-2 py-1"
					>
						<span className="num w-5 text-xs text-muted">
							{ i + 1 }
						</span>
						<span className="flex-1 text-sm text-ink">
							{ labelOf( v ) }
						</span>
						<button
							type="button"
							className={ ghostBtn + ' px-1 py-0' }
							disabled={ disabled || i === 0 }
							onClick={ () => move( i, -1 ) }
							aria-label={ `Move ${ labelOf( v ) } up` }
						>
							↑
						</button>
						<button
							type="button"
							className={ ghostBtn + ' px-1 py-0' }
							disabled={ disabled || i === selected.length - 1 }
							onClick={ () => move( i, 1 ) }
							aria-label={ `Move ${ labelOf( v ) } down` }
						>
							↓
						</button>
						<button
							type="button"
							className={ ghostBtn + ' px-1 py-0' }
							disabled={ disabled }
							onClick={ () =>
								onChange( selected.filter( ( x ) => x !== v ) )
							}
							aria-label={ `Remove ${ labelOf( v ) }` }
						>
							×
						</button>
					</li>
				) ) }
			</ul>
			<select
				className={ inputCls }
				disabled={ disabled || available.length === 0 }
				value=""
				onChange={ ( e ) => {
					if ( e.target.value ) {
						onChange( [ ...selected, e.target.value ] );
					}
				} }
			>
				<option value="">Add…</option>
				{ available.map( ( o ) => (
					<option key={ o.value } value={ o.value }>
						{ o.label }
					</option>
				) ) }
			</select>
		</div>
	);
}

// Editable {key, label, points} rows. Reserved keys (the engine-assigned
// pairing bye) can be re-priced but never removed.
function ByeTypeList( { field, rows, onChange, disabled } ) {
	const reserved = field.reservedKeys || [];
	const list = Array.isArray( rows ) ? rows : [];

	const patch = ( index, changes ) =>
		onChange(
			list.map( ( row, i ) => ( i === index ? { ...row, ...changes } : row ) )
		);

	return (
		<div className="flex flex-col gap-2">
			{ list.map( ( row, i ) => {
				const isReserved = reserved.includes( row.key );
				return (
					<div key={ row.key || i } className="flex items-center gap-2">
						<input
							className={ inputCls + ' w-40' }
							value={ row.key || '' }
							disabled={ disabled || isReserved }
							onChange={ ( e ) =>
								patch( i, { key: e.target.value } )
							}
							aria-label="Bye key"
						/>
						<input
							className={ inputCls + ' flex-1' }
							value={ row.label || '' }
							disabled={ disabled }
							onChange={ ( e ) =>
								patch( i, { label: e.target.value } )
							}
							aria-label="Bye label"
						/>
						<input
							type="number"
							step="0.5"
							className={ inputCls + ' num w-20' }
							value={ row.points ?? 0 }
							disabled={ disabled }
							onChange={ ( e ) =>
								patch( i, {
									points: parseFloat( e.target.value ),
								} )
							}
							aria-label="Bye points"
						/>
						<button
							type="button"
							className={ ghostBtn + ' px-1 py-0' }
							disabled={ disabled || isReserved }
							title={
								isReserved
									? 'The pairing bye is assigned by the engine and can’t be removed.'
									: 'Remove'
							}
							onClick={ () =>
								onChange( list.filter( ( _, k ) => k !== i ) )
							}
						>
							×
						</button>
					</div>
				);
			} ) }
			<div>
				<button
					type="button"
					className={ ghostBtn + ' px-0' }
					disabled={ disabled }
					onClick={ () =>
						onChange( [
							...list,
							{ key: '', label: '', points: 0 },
						] )
					}
				>
					+ Add bye type
				</button>
			</div>
		</div>
	);
}

// Sub-fields for a parametric tiebreak (Buchholz method, direct-encounter
// group size, …), shown only while that metric is actually selected.
function TiebreakConfig( { config, selected, values, onChange, disabled } ) {
	const entries = Object.entries( config || {} ).filter( ( [ metric ] ) =>
		selected.includes( metric )
	);
	if ( entries.length === 0 ) {
		return null;
	}

	return (
		<div className="mt-3 flex flex-col gap-3 border-l-2 border-rule pl-3">
			{ entries.map( ( [ metric, fields ] ) => (
				<div key={ metric }>
					{ fields.map( ( f ) => {
						const current =
							values?.[ metric ]?.[ f.key ] ?? f.default;
						const set = ( v ) =>
							onChange( {
								...values,
								[ metric ]: {
									...( values?.[ metric ] || {} ),
									[ f.key ]: v,
								},
							} );
						return (
							<label
								key={ f.key }
								className="flex items-center gap-2 text-sm text-ink-3"
							>
								<span className="min-w-[16rem]">{ f.label }</span>
								{ f.type === 'select' ? (
									<select
										className={ inputCls }
										value={ current }
										disabled={ disabled }
										onChange={ ( e ) => set( e.target.value ) }
									>
										{ f.options.map( ( o ) => (
											<option
												key={ o.value }
												value={ o.value }
												disabled={
													o.implemented === false
												}
											>
												{ o.label }
												{ o.implemented === false
													? ' (not implemented)'
													: '' }
											</option>
										) ) }
									</select>
								) : (
									<input
										type="number"
										step={ f.step ?? 1 }
										className={ inputCls + ' num w-24' }
										value={ current }
										disabled={ disabled }
										onChange={ ( e ) =>
											set( parseFloat( e.target.value ) )
										}
									/>
								) }
							</label>
						);
					} ) }
				</div>
			) ) }
		</div>
	);
}

function ScoringGroup( { group, values, setValues, disabled } ) {
	if ( group.group === 'game_outcomes' ) {
		return (
			<section>
				<SectionTitle>Game outcomes</SectionTitle>
				<div className="flex flex-wrap gap-4">
					{ group.fields.map( ( f ) => (
						<label
							key={ f.key }
							className="flex items-center gap-2 text-sm text-ink-3"
						>
							<span>{ f.label }</span>
							<input
								type="number"
								step={ f.step ?? 0.5 }
								className={ inputCls + ' num w-20' }
								value={
									values.gameOutcomes?.[ f.key ] ?? f.default
								}
								disabled={ disabled }
								onChange={ ( e ) =>
									setValues( {
										...values,
										gameOutcomes: {
											...values.gameOutcomes,
											[ f.key ]: parseFloat(
												e.target.value
											),
										},
									} )
								}
							/>
						</label>
					) ) }
				</div>
			</section>
		);
	}

	if ( group.group === 'bye_types' ) {
		return (
			<section>
				<SectionTitle hint="A player’s bye scores the points of its type. The pairing bye is assigned by the engine.">
					Bye types
				</SectionTitle>
				<ByeTypeList
					field={ group }
					rows={ values.byeTypes }
					disabled={ disabled }
					onChange={ ( byeTypes ) =>
						setValues( { ...values, byeTypes } )
					}
				/>
			</section>
		);
	}

	if ( group.group === 'rank_by' ) {
		return (
			<section>
				<SectionTitle>Rank by</SectionTitle>
				<select
					className={ inputCls }
					value={ values.rankBy ?? group.default }
					disabled={ disabled }
					onChange={ ( e ) =>
						setValues( { ...values, rankBy: e.target.value } )
					}
				>
					{ group.options.map( ( o ) => (
						<option
							key={ o.value }
							value={ o.value }
							disabled={ o.implemented === false }
						>
							{ o.label }
						</option>
					) ) }
				</select>
			</section>
		);
	}

	if ( group.group === 'tiebreakers' ) {
		const selected = values.tiebreakers ?? [];
		return (
			<section>
				<SectionTitle hint="Applied in order when players are level on the ranking metric.">
					Tie-breaks
				</SectionTitle>
				<OrderedMultiSelect
					options={ group.options }
					value={ selected }
					disabled={ disabled }
					onChange={ ( tiebreakers ) =>
						setValues( { ...values, tiebreakers } )
					}
				/>
				<TiebreakConfig
					config={ group.config }
					selected={ selected }
					values={ values.tiebreakConfig }
					disabled={ disabled }
					onChange={ ( tiebreakConfig ) =>
						setValues( { ...values, tiebreakConfig } )
					}
				/>
			</section>
		);
	}

	return null;
}

export function TournamentSettingsDialog( { season, onClose } ) {
	const queryClient = useQueryClient();
	const [ scoring, setScoring ] = useState( null );
	const [ display, setDisplay ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'season-settings', season.id ],
		queryFn: () => api.get( `seasons/${ season.id }/settings` ),
	} );

	useEffect( () => {
		if ( data ) {
			setScoring( data.scoring?.values ?? null );
			setDisplay( data.display?.values ?? null );
		}
	}, [ data ] );

	const save = useMutation( {
		mutationFn: ( payload ) => api.patch( `seasons/${ season.id }`, payload ),
		onSuccess: () => {
			setSaved( true );
			queryClient.invalidateQueries( {
				queryKey: [ 'season-settings', season.id ],
			} );
			queryClient.invalidateQueries( { queryKey: [ 'seasons' ] } );
		},
	} );

	const scoringLocked = data?.scoring_locked ?? false;
	const scoringFields = data?.scoring?.fields ?? null;
	const displayFields = data?.display?.fields ?? null;

	const submit = () => {
		setSaved( false );
		const payload = {};
		if ( scoring && ! scoringLocked ) {
			payload.scoring_settings = scoring;
		}
		if ( display ) {
			payload.display_settings = display;
		}
		save.mutate( payload );
	};

	let body;
	if ( isLoading ) {
		body = <Notice>Loading…</Notice>;
	} else if ( isError ) {
		body = <Notice>Couldn’t load settings. Please try again.</Notice>;
	} else {
		body = (
			<div className="flex flex-col gap-6">
				{ scoringLocked && (
					<Notice>
						A round has been completed, so scoring settings are
						locked for this tournament. Display settings can still be
						changed.
					</Notice>
				) }

				{ scoringFields && scoring ? (
					scoringFields.map( ( group ) => (
						<ScoringGroup
							key={ group.group }
							group={ group }
							values={ scoring }
							setValues={ setScoring }
							disabled={ scoringLocked || save.isPending }
						/>
					) )
				) : (
					<Notice>
						Scoring for the “{ data?.scoring_system }” system isn’t
						configurable yet.
					</Notice>
				) }

				{ displayFields && display && (
					<section>
						<SectionTitle hint="Which columns the standings table shows, in order.">
							Standings columns
						</SectionTitle>
						<OrderedMultiSelect
							options={ displayFields[ 0 ].options }
							value={ display.columns }
							disabled={ save.isPending }
							onChange={ ( columns ) =>
								setDisplay( { ...display, columns } )
							}
						/>
					</section>
				) }
			</div>
		);
	}

	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onClose }
		>
			<div
				className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="mb-4 flex items-start justify-between gap-4">
					<div>
						<h2 className="font-serif text-2xl leading-tight">
							Tournament settings
						</h2>
						<p className="mt-1 text-sm text-ink-3">
							{ season.name }
						</p>
					</div>
					<button
						type="button"
						className={ ghostBtn }
						onClick={ onClose }
					>
						Close
					</button>
				</div>

				{ body }

				{ save.isError && (
					<p className="mt-3 text-sm text-loss">
						{ errorMessage( save.error ) }
					</p>
				) }
				{ saved && ! save.isError && (
					<p className="mt-3 text-sm text-win">Settings saved.</p>
				) }

				{ ! isLoading && ! isError && (
					<div className="mt-6 flex justify-end gap-2">
						<button
							type="button"
							className={ ghostBtn }
							disabled={ save.isPending }
							onClick={ onClose }
						>
							Cancel
						</button>
						<button
							type="button"
							className={ primaryBtn }
							disabled={ save.isPending }
							onClick={ submit }
						>
							{ save.isPending ? 'Saving…' : 'Save settings' }
						</button>
					</div>
				) }
			</div>
		</div>
	);
}
