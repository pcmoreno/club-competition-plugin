import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { Notice } from '../components/ui';

// ADMIN. Modal launched from the Tournaments tab. Lists the plugin-shipped
// fixtures (GET /fixtures) and loads one on demand (POST /fixtures/load). The
// load is DESTRUCTIVE for the target season — it replaces that season's rounds,
// games and standings (players are upserted by name and preserved; other
// seasons are untouched) — so each fixture requires an explicit in-dialog
// confirm before it fires. On success the returned row counts are shown and the
// seasons list is invalidated so the Tournaments table behind the dialog
// refreshes.

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:opacity-60';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink disabled:opacity-60';

function errorMessage( err ) {
	if ( err instanceof ApiError ) {
		return err.message;
	}
	return 'Something went wrong. Please try again.';
}

export function ImportSeasonDialog( { onClose } ) {
	const queryClient = useQueryClient();
	const [ confirming, setConfirming ] = useState( null ); // fixture name awaiting confirm
	const [ result, setResult ] = useState( null ); // { counts } on success

	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'fixtures' ],
		queryFn: () => api.get( 'fixtures' ),
	} );

	const load = useMutation( {
		mutationFn: ( name ) => api.post( 'fixtures/load', { name } ),
		onSuccess: ( counts ) => {
			setResult( counts );
			setConfirming( null );
			queryClient.invalidateQueries( { queryKey: [ 'seasons' ] } );
		},
	} );

	let body;
	if ( isLoading ) {
		body = <Notice>Loading…</Notice>;
	} else if ( isError || ! Array.isArray( data ) ) {
		body = <Notice>Couldn’t load fixtures. Please try again.</Notice>;
	} else if ( data.length === 0 ) {
		body = <Notice>No fixtures are shipped with the plugin.</Notice>;
	} else {
		body = (
			<ul className="flex flex-col gap-2">
				{ data.map( ( f ) => (
					<li
						key={ f.name }
						className="rounded border border-rule bg-surface p-3"
					>
						<div className="font-mono text-sm text-ink">
							{ f.name }
						</div>
						{ f.description && (
							<div className="mt-0.5 text-xs text-ink-3">
								{ f.description }
							</div>
						) }
						<div className="mt-2">
							<button
								type="button"
								className={ primaryBtn }
								disabled={ load.isPending }
								onClick={ () => {
									setResult( null );
									load.reset();
									setConfirming( f.name );
								} }
							>
								Load
							</button>
						</div>
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onClose }
		>
			<div
				className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="mb-4 flex items-start justify-between gap-4">
					<div>
						<h2 className="font-serif text-2xl leading-tight">
							Import season
						</h2>
						<p className="mt-1 text-sm text-ink-3">
							Seed a season from a shipped fixture.
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

				{ result ? (
					<div className="rounded border border-rule bg-surface p-4">
						<p className="mb-2 font-medium text-ink">
							Import complete.
						</p>
						<dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-ink-3">
							{ Object.entries( result ).map(
								( [ key, value ] ) => (
									<div
										key={ key }
										className="flex justify-between"
									>
										<dt className="capitalize">{ key }</dt>
										<dd className="num font-mono text-ink">
											{ value }
										</dd>
									</div>
								)
							) }
						</dl>
						<div className="mt-4 text-right">
							<button
								type="button"
								className={ primaryBtn }
								onClick={ onClose }
							>
								Done
							</button>
						</div>
					</div>
				) : (
					<>
						{ body }
						{ load.isError && (
							<p className="mt-3 text-sm text-loss">
								{ errorMessage( load.error ) }
							</p>
						) }
					</>
				) }

				{ confirming && ! result && (
					<div className="mt-4 rounded border border-loss/40 bg-surface p-4">
						<p className="text-sm text-ink">
							This replaces the rounds, games and standings for{ ' ' }
							<strong>
								{ data?.find?.( ( f ) => f.name === confirming )
									?.description || confirming }
							</strong>
							. Players are preserved and other seasons are
							untouched. Members and admins are kept. Continue?
						</p>
						<div className="mt-3 flex justify-end gap-2">
							<button
								type="button"
								className={ ghostBtn }
								disabled={ load.isPending }
								onClick={ () => setConfirming( null ) }
							>
								Cancel
							</button>
							<button
								type="button"
								className="rounded bg-loss px-4 py-2 text-sm font-medium text-paper hover:opacity-90 disabled:opacity-60"
								disabled={ load.isPending }
								onClick={ () => load.mutate( confirming ) }
							>
								{ load.isPending
									? 'Importing…'
									: 'Yes, replace' }
							</button>
						</div>
					</div>
				) }
			</div>
		</div>
	);
}
