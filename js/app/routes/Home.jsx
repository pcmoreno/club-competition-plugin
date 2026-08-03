import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { Page } from '../layout/Page';
import { Link } from '../router/router';
import { Notice, formatDate } from '../components/ui';
import { Square, TIME_CONTROL_LABELS } from '../components/game';
import { AbsenceCard } from './AbsenceCard';
import { keys } from '../api/keys';

// MEMBER. The signed-in member's home page, and where signing in lands you.
//
// The one view that spans seasons — every other viewer route is scoped by the
// tournament switcher, which is why the switcher is hidden here. Several
// tournaments can run at once, so "your next pairing" and "your tournaments"
// are both plural by nature. Backed by a single GET /me/home, which composes it
// server-side; the client never fans out per season.
//
// "I can't play this round" sits under the next-game cards (AbsenceCard).

function Card( { children, className = '' } ) {
	return (
		<div
			className={ `rounded border border-rule bg-surface p-5 shadow-sm ${ className }` }
		>
			{ children }
		</div>
	);
}

function SectionTitle( { children, action } ) {
	return (
		<div className="mb-3 mt-8 flex items-baseline justify-between gap-3">
			<h2 className="font-serif text-[22px] leading-tight">
				{ children }
			</h2>
			{ action }
		</div>
	);
}

// A single upcoming board: which tournament, which round, and who you're
// playing. A pairing bye renders in the same frame — "you're not playing this
// round" is as much an answer as a board number.
function NextPairingCard( { pairing } ) {
	const { season, round, opponent } = pairing;
	const date = formatDate( round.date );

	return (
		<Card>
			<div className="flex items-baseline justify-between gap-3">
				<span className="text-xs uppercase tracking-wide text-muted">
					{ season.name }
				</span>
				<span className="text-xs text-muted">
					Round { round.number }
					{ date ? ` · ${ date }` : '' }
				</span>
			</div>

			{ pairing.is_bye ? (
				<p className="mt-3 text-ink-2">
					You have a bye this round — no game to play.
				</p>
			) : (
				<>
					<div className="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
						<Square color={ pairing.color } />
						<span className="text-lg text-ink">
							{ opponent ? (
								<Link
									to={ `/seasons/${ season.id }/players/${ opponent.player_id }` }
									className="text-ink no-underline hover:text-accent"
								>
									{ opponent.name }
								</Link>
							) : (
								'Opponent to be confirmed'
							) }
						</span>
						{ opponent?.rating ? (
							<span className="num font-mono text-ink-3">
								({ opponent.rating })
							</span>
						) : null }
					</div>
					<p className="mt-2 text-sm text-ink-3">
						You play{ ' ' }
						{ pairing.color === 'white' ? 'White' : 'Black' }
						{ pairing.board ? ` on board ${ pairing.board }` : '' }.
						{ round.status === 'published'
							? ' Pairings may still change.'
							: '' }
					</p>
				</>
			) }
		</Card>
	);
}

// Rank as "4th of 32" — the field size matters as much as the position.
function rankLabel( rank, fieldSize ) {
	if ( ! rank ) {
		return null;
	}
	return fieldSize ? `${ rank } of ${ fieldSize }` : String( rank );
}

function Figure( { label, value } ) {
	return (
		<div>
			<div className="num font-mono text-lg text-ink">
				{ value ?? '—' }
			</div>
			<div className="text-xs uppercase tracking-wide text-muted">
				{ label }
			</div>
		</div>
	);
}

// One tournament you're in or have finished. Figures come from your latest
// standings snapshot, so they're blank until the first round completes.
function TournamentCard( { entry, playerId } ) {
	const { season } = entry;
	const started = entry.games !== null && entry.games !== undefined;
	const timeControl = TIME_CONTROL_LABELS[ season.time_control ];

	return (
		<Card>
			<div className="flex items-baseline justify-between gap-3">
				<Link
					to={ `/seasons/${ season.id }/players/${ playerId }` }
					className="font-medium text-ink no-underline hover:text-accent"
				>
					{ season.name }
				</Link>
				<span className="text-xs text-muted">
					{ timeControl }
					{ entry.category ? ` · ${ entry.category }` : '' }
				</span>
			</div>

			{ started ? (
				<div className="mt-4 grid grid-cols-4 gap-3">
					<Figure
						label="Rank"
						value={ rankLabel( entry.rank, entry.field_size ) }
					/>
					<Figure label="Points" value={ entry.points } />
					<Figure
						label="W/D/L"
						value={ `${ entry.wins }/${ entry.draws }/${ entry.losses }` }
					/>
					<Figure label="Games" value={ entry.games } />
				</div>
			) : (
				<p className="mt-3 text-sm text-ink-3">No rounds played yet.</p>
			) }
		</Card>
	);
}

export function Home() {
	const { data, isLoading, isError } = useQuery( {
		queryKey: keys.home(),
		queryFn: () => api.get( 'me/home' ),
	} );

	if ( isLoading ) {
		return (
			<Page>
				<Notice>Loading…</Notice>
			</Page>
		);
	}
	if ( isError || ! data ) {
		return (
			<Page>
				<Notice>Couldn’t load your home page. Please try again.</Notice>
			</Page>
		);
	}

	const {
		player,
		next_pairings: nextPairings,
		current,
		past,
		declinable,
	} = data;

	return (
		<Page>
			<h1 className="font-serif text-[38px] leading-[1.1]">
				{ player.name }
			</h1>

			<SectionTitle>Your next game</SectionTitle>
			{ nextPairings.length === 0 ? (
				<Notice>
					{ current.length === 0
						? 'You’re not enrolled in a tournament right now.'
						: 'No pairings published yet. They appear here as soon as the next round goes out.' }
				</Notice>
			) : (
				<div className="grid gap-3 md:grid-cols-2">
					{ nextPairings.map( ( p ) => (
						<NextPairingCard key={ p.round.id } pairing={ p } />
					) ) }
				</div>
			) }

			<AbsenceCard declinable={ declinable ?? [] } />

			{ current.length > 0 && (
				<>
					<SectionTitle>Playing now</SectionTitle>
					<div className="grid gap-3 md:grid-cols-2">
						{ current.map( ( entry ) => (
							<TournamentCard
								key={ entry.season.id }
								entry={ entry }
								playerId={ player.id }
							/>
						) ) }
					</div>
				</>
			) }

			{ past.length > 0 && (
				<>
					<SectionTitle
						action={
							<Link
								to={ `/players/${ player.id }` }
								className="text-sm"
							>
								All tournaments →
							</Link>
						}
					>
						Recently finished
					</SectionTitle>
					<div className="grid gap-3 md:grid-cols-2">
						{ past.slice( 0, 4 ).map( ( entry ) => (
							<TournamentCard
								key={ entry.season.id }
								entry={ entry }
								playerId={ player.id }
							/>
						) ) }
					</div>
				</>
			) }
		</Page>
	);
}
