import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:cursor-not-allowed disabled:opacity-50';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';

// A download/parse failure comes back as a 409 carrying the real reason in
// body.error (e.g. "KNSB download failed: HTTP 503"); surface it.
function errorMessage( err ) {
	if ( err instanceof ApiError && err.body?.error ) {
		return err.body.error;
	}
	return 'Something went wrong. Please try again.';
}

function Row( { label, value } ) {
	return (
		<div className="flex items-baseline justify-between gap-3">
			<dt className="text-sm text-muted">{ label }</dt>
			<dd className="text-right text-sm text-ink">{ value }</dd>
		</div>
	);
}

// ADMIN. Opened from the roster's Actions menu. Shows which KNSB rating list is
// currently stored on the server (date + when it was downloaded + how many
// players), and downloads the latest one server-side (the host has no CLI/cron
// to run the fetch). Fetching only refreshes the stored list — it does NOT
// change any player's rating; that's applied per player from their Sync action.
export function FetchKnsbDialog( { onClose } ) {
	const queryClient = useQueryClient();
	const status = useQuery( {
		queryKey: [ 'knsb-status' ],
		queryFn: () => api.get( 'knsb/status' ),
	} );
	const fetchList = useMutation( {
		mutationFn: () => api.post( 'knsb/fetch' ),
		// The fetch endpoint returns the fresh status, so seed the query with it
		// — the card below updates without a refetch.
		onSuccess: ( data ) => queryClient.setQueryData( [ 'knsb-status' ], data ),
	} );

	const s = status.data;

	return (
		<div
			className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4"
			onClick={ onClose }
		>
			<div
				className="w-full max-w-md rounded-lg bg-paper p-6 shadow-md"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h2 className="font-serif text-2xl leading-tight">
					Fetch KNSB ratings
				</h2>
				<p className="mt-2 text-sm text-ink-3">
					Downloads the latest KNSB classical rating list from
					schaakbond.nl and stores it on the server. This doesn’t change
					any player’s rating — apply it per player from their Sync
					action.
				</p>

				<div className="mt-4 rounded border border-rule bg-surface p-4">
					{ status.isLoading ? (
						<p className="text-sm text-muted">
							Checking the stored list…
						</p>
					) : status.isError ? (
						<p className="text-sm text-loss">
							Couldn’t read the current list.
						</p>
					) : s?.available ? (
						<dl className="space-y-1">
							<Row
								label="List date"
								value={ s.list_date ?? 'unknown' }
							/>
							<Row
								label="Downloaded"
								value={ s.fetched_at ?? 'unknown' }
							/>
							<Row
								label="Players"
								value={ ( s.count ?? 0 ).toLocaleString() }
							/>
						</dl>
					) : (
						<p className="text-sm text-ink-3">
							No list has been downloaded yet.
						</p>
					) }
				</div>

				{ fetchList.isError && (
					<p className="mt-3 text-sm text-loss">
						{ errorMessage( fetchList.error ) }
					</p>
				) }
				{ fetchList.isSuccess && (
					<p className="mt-3 text-sm text-win">
						Rating list updated.
					</p>
				) }

				<div className="mt-5 flex justify-end gap-2">
					<button
						type="button"
						className={ ghostBtn }
						onClick={ onClose }
						disabled={ fetchList.isPending }
					>
						Close
					</button>
					<button
						type="button"
						className={ primaryBtn }
						onClick={ () => fetchList.mutate() }
						disabled={ fetchList.isPending }
					>
						{ fetchList.isPending
							? 'Fetching…'
							: s?.available
							? 'Fetch again'
							: 'Fetch now' }
					</button>
				</div>
			</div>
		</div>
	);
}
