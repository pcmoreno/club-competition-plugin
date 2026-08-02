import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { Dialog } from '../components/Dialog';
import { ChangeRow } from '../components/ui';
import { keys } from '../api/keys';

const primaryBtn =
	'rounded bg-ink px-4 py-2 text-sm font-medium text-paper hover:bg-ink-2 disabled:cursor-not-allowed disabled:opacity-50';
const ghostBtn =
	'rounded px-4 py-2 text-sm font-medium text-ink-3 hover:text-ink';

// A 409 ("No KNSB rating list has been fetched yet.") carries the real reason in
// body.error; surface it.
function errorMessage( err ) {
	if ( err instanceof ApiError && err.body?.error ) {
		return err.body.error;
	}
	return 'Something went wrong. Please try again.';
}

// Why a player couldn't be synced. The backend sends a machine reason; the
// wording lives here.
const FAILURE_TEXT = {
	not_listed: 'not in the current rating list',
	name_conflict: 'KNSB name is already taken by another player',
};

const FIELD_LABEL = {
	name: 'Name',
	birth_year: 'Birth year',
	knsb_elo: 'Rating',
};

function Count( { label, value, muted = false } ) {
	return (
		<div className="flex items-baseline justify-between gap-3">
			<dt
				className={ `text-sm ${ muted ? 'text-muted' : 'text-ink-3' }` }
			>
				{ label }
			</dt>
			<dd className="num text-right font-mono text-sm text-ink">
				{ value }
			</dd>
		</div>
	);
}

// The post-run report: what the sync did, per player. Nothing is listed for the
// players that were already up to date — only the changes and the misses need
// the admin's eyes.
function Report( { report } ) {
	return (
		<>
			<dl className="mt-4 space-y-1 rounded border border-rule bg-surface p-4">
				<Count label="Updated" value={ report.updated } />
				<Count label="Already up to date" value={ report.unchanged } />
				<Count
					label="Skipped (no KNSB id)"
					value={ report.skipped }
					muted
				/>
				<Count label="Not synced" value={ report.failed } muted />
			</dl>

			{ report.changes.length > 0 && (
				<>
					<h3 className="mt-5 text-xs uppercase tracking-wide text-muted">
						Changes
					</h3>
					<ul className="mt-2 space-y-3">
						{ report.changes.map( ( c ) => (
							<li
								key={ c.id }
								className="rounded border border-rule-soft p-3"
							>
								<p className="text-sm font-medium text-ink">
									{ c.name }
								</p>
								<dl className="mt-1.5 space-y-1 text-sm">
									{ Object.entries( c.fields ).map(
										( [ field, v ] ) => (
											<ChangeRow
												key={ field }
												label={
													FIELD_LABEL[ field ] ??
													field
												}
												before={ v.before }
												after={ v.after }
												mono={ field !== 'name' }
											/>
										)
									) }
								</dl>
							</li>
						) ) }
					</ul>
				</>
			) }

			{ report.failures.length > 0 && (
				<>
					<h3 className="mt-5 text-xs uppercase tracking-wide text-muted">
						Not synced
					</h3>
					<ul className="mt-2 space-y-1 text-sm">
						{ report.failures.map( ( f ) => (
							<li key={ f.id } className="text-ink-3">
								<span className="text-ink">{ f.name }</span>
								{ ' — ' }
								{ FAILURE_TEXT[ f.reason ] ??
									'could not be synced' }
							</li>
						) ) }
					</ul>
				</>
			) }
		</>
	);
}

// ADMIN. Opened from the roster's Actions menu. Applies the stored KNSB list to
// every player who has a KNSB id (POST /players/knsb-sync) — active or not —
// and reports what changed. Players without a KNSB id are skipped; one that
// can't be matched is reported and the rest still sync. The list itself is
// downloaded separately, via "Fetch KNSB ratings".
export function SyncKnsbDialog( { onClose } ) {
	const queryClient = useQueryClient();
	const status = useQuery( {
		queryKey: keys.knsbStatus(),
		queryFn: () => api.get( 'knsb/status' ),
	} );
	const sync = useMutation( {
		mutationFn: () => api.post( 'players/knsb-sync' ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: keys.adminPlayers() } ),
	} );

	const s = status.data;
	const report = sync.data;

	return (
		<Dialog
			title="Sync KNSB ratings"
			description="Applies the stored KNSB list to every player with a KNSB id. Their name, birth year and rating are overwritten with the official KNSB values."
			size="lg"
			scroll
			busy={ sync.isPending }
			onClose={ onClose }
		>
			{ report ? (
				<Report report={ report } />
			) : (
				<>
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
								<Count
									label="List date"
									value={ s.list_date ?? 'unknown' }
								/>
								<Count
									label="Downloaded"
									value={ s.fetched_at ?? 'unknown' }
								/>
							</dl>
						) : (
							<p className="text-sm text-ink-3">
								No list has been downloaded yet — fetch one
								first with “Fetch KNSB ratings”.
							</p>
						) }
					</div>

					{ sync.isError && (
						<p className="mt-3 text-sm text-loss">
							{ errorMessage( sync.error ) }
						</p>
					) }
				</>
			) }

			<div className="mt-5 flex justify-end gap-2">
				<button
					type="button"
					className={ report ? primaryBtn : ghostBtn }
					onClick={ onClose }
					disabled={ sync.isPending }
				>
					{ report ? 'Done' : 'Cancel' }
				</button>
				{ ! report && (
					<button
						type="button"
						className={ primaryBtn }
						onClick={ () => sync.mutate() }
						disabled={ sync.isPending || ! s?.available }
					>
						{ sync.isPending ? 'Syncing…' : 'Sync all' }
					</button>
				) }
			</div>
		</Dialog>
	);
}
