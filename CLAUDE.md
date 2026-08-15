# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Club Competition Manager** is a WordPress plugin that replaces desktop-based chess competition management (Sevilla software) with a web application. It manages pairings, standings, and results for Schaakclub Santpoort's internal chess competitions.

### Key Goals

Goals, not a feature list — several are still unbuilt. Where this file says
something is missing, believe it and check the code before assuming otherwise.

| Goal | State |
|---|---|
| Live viewer: standings, player stats | Built |
| Member home page (`/home`) | Built |
| Admin: rounds, manual pairings, results | Built |
| Member invitations and auth (no WP account) | Built |
| Admin invitations | Built — first admin only, see below |
| Member self-report absence | Built — see below |
| Standing absence per enrolment | Built — admin Absences tab, see below |
| KNSB rating integration | Fetch, per-player apply and bulk sync built; no cron |
| Keizer pairing and scoring | Built — `KeizerScoring` + `KeizerPairing`, verified against the shipped 2025-26 fixture |
| Round-robin pairing | Built — whole fixture generated as a Berger table |
| Swiss pairing | **Not implemented** — no engine, so it is Manual under another name. Not selectable |
| Team play (team vs team) | **Not implemented, and not selectable.** The roster half is built — `seasons.is_team`, the Teams tab, assignments, board order — but nothing pairs team against team, sums boards into a match result, or produces team standings. The engine never reads `is_team`. Both dialogs render Team disabled and `SeasonController::requireTeamPlayImplemented` refuses it; a season already on it stays editable. Not a pairing system: it is orthogonal to how a round is paired |
| Email notifications | Invites, password resets, absence notices |
| Round dates in the admin UI | Built — set per round on the Pairings tab |
| PDF generation | **Not implemented** (dompdf is installed, unused) |

## Architecture

### Core Design Pattern

The plugin uses **clean architecture** with clear separation of concerns:

```
Request (REST API or Shortcode)
    ↓
Controller (expose business logic)
    ↓
Service (orchestrate business logic)
    ↓
Repository (data access)
    ↓
Entity (data models)
    ↓
Database
```

### Technology Stack

| Layer | Technology | Notes |
|-------|-----------|-------|
| Plugin Runtime | PHP 8.2+ / WordPress 5.0+ | Entry point: `club-competition-plugin.php` |
| DI Container | Symfony DependencyInjection | Configured in `src/Container.php` |
| Database | MySQL 5.7+ via Doctrine DBAL | Tables built from `SCS_TABLE_PREFIX` — **not** hardcoded `wp_scs_` |
| REST API | WordPress REST API | Custom endpoints at `/wp-json/scs/v1/` |
| Authentication | lcobucci/jwt + Symfony CSRF | JWT in an httpOnly cookie, never localStorage |
| Validation | Symfony Validator | Input validation on all DTOs |
| Serialization | Hand-rolled `SerializerService` | Entity → array; visibility via groups |
| Frontend | React 18 via `@wordpress/element` | Embedded via shortcode `[clubcompetitie]` |
| Data fetching | TanStack Query + raw `fetch` | Query keys come from `js/app/api/keys.js` |
| Styling | Tailwind v4 | Viewer utilities are deliberately **unlayered** — see below |
| Email | WordPress `wp_mail` | Plain text, built inline in `EmailNotificationService` — no templates |

Declared but unused today: `dompdf` (PDF pairing sheets are planned, nothing
renders one yet), `@wordpress/api-fetch` and `dayjs`. Don't take a dependency in
`composer.json` / `package.json` as evidence a feature exists.

Symfony's full Security component is **not** used — authentication is a
`permission_callback` per route plus `AuthContextService`. Only the CSRF piece
of Symfony Security is wired.

### API Architecture

**Frontend → Backend only.** React frontend communicates exclusively with the local REST API (`/wp-json/scs/v1/`). All external API calls (KNSB ratings, Lichess, etc.) are handled by the PHP backend. The frontend never makes direct calls to external services.

### Folder Structure Logic

```
src/
├── Entity/           Data models (Player, Season, Round, Game, etc.) + Enum/
├── Repository/       Database access layer (queries, CRUD operations)
├── Services/         Business logic (RoundService, AuthService, SeasonImportService, …)
├── Engine/           Pluggable pairing + scoring engines, their settings and resolvers
├── Security/         RequestContext (scheme detection), CookieCsrfTokenStorage
├── Controller/       REST API controllers (expose Services to HTTP)
├── Request/          Request DTOs, validated with Symfony Validator
├── Exception/        Custom exceptions (NotFoundException, ConflictException, etc.)
├── Command/          WP-CLI commands (migrate, create-admin, fetch-knsb-ratings)
└── Container.php     All Symfony DI wiring — there is no config/ directory

includes/            WordPress integration (schema/migrations, REST routes, shortcode, assets)
js/app/              The React app — viewer and admin both live here, one bundle
fixtures/            Shipped season fixtures (JSON), imported via the admin Import dialog
dev/                 Local Docker env, design/spec notes (page-inventory.md, engine-architecture.md)
```

`tests/Unit/Engine/` is tracked and covers Keizer scoring and pairing, run with
`composer test`. There is no `tests/Integration` in git, and `phpunit.xml` no
longer declares one: PHPUnit treats a missing testsuite directory as a fatal
error, so a clean clone could not run the suite at all. Everything outside the
engine is still verified by hand in the UI.

## Key Concepts

### Keizer

`ScoringStrategyResolver` builds a strategy for both scoring systems, so scoring
gates nothing. **Selectability is now gated on pairing** instead
(`PairingSystem::isImplemented()`): three of the four systems can be chosen for a
new tournament — Keizer, Manual and Round-robin. Swiss is refused: it has no
pairing engine, so it would be Manual under another label. Both dialogs render it
disabled and labelled "(not implemented)", and `UpdateSeasonRequest` still
*accepts* every system so a season already on one stays editable.

**Scoring** (`src/Engine/Scoring/KeizerScoring.php`):

```
score = OwnV + Aalsmeer(round) × OwnV
      + Σ games     Par(result) × OppV
      + Σ absences  Par(reason) × OwnV
```

`Par` is the shared `gameOutcomes` / `byeTypes` knobs — the same numbers
standard scoring adds to a total, here used as coefficients. Values come from a
**Position range** ladder (`ValueLadder`): top of the ranking takes `topValue`,
bottom takes `bottomValue`, everyone linear between, rounded. Defaults are
200/100, fitted to the club's own history.

Two things to know:

- **The whole season is re-priced every round**, not incremented — a win against
  someone who has since climbed is worth more today. This costs nothing, because
  `computeStandings` already receives every game played so far.
- **Two populations.** The ladder spans everyone *enrolled*; the standings list
  only those who have played or taken a bye. Values depend on how many rungs
  exist, so counting only participants would move everyone's value whenever a
  new member debuted.

Values come from the previous round's ranking (Sevilla's `Revaluation: Classic`
— one pass, not an iteration to a fixed point), so **Keizer is sequential**:
completing round N when N−1 has no snapshot throws a `ConflictException`.

**Pairing** (`src/Engine/Pairing/KeizerPairing.php`, a `PerRoundPairing`) sorts
by Keizer score or by Sevilla's damped percentage
`(wins + ½ draws + SC) / (games + GC)`, then pairs neighbours. It works in from
**both ends** by default rather than straight down the list, which keeps the
awkward remainder in the middle of the field instead of dumping it on the
weakest players. Standard and colour-aware algorithms are built; the weighted
variants are exposed but coerce back to standard.

**Rematches are discouraged, not forbidden.** `Pairing ▸ Values` sets a minimum
gap (`roundsBetweenSamePairing`, 10) and a season maximum (`maxSamePairings`,
4), and the engine treats both as penalties rather than filters — a thin field
still gets a board. The oracle confirms both: its worst-repeated pair meets
exactly four times, and 97 of 110 rematches respect the ten-round gap while 13
break it, which is what a preference looks like in the data. The first rematch
of the season falls in round 12, directly explained by the window.

Colour has two caps on the same tab: `maxColorDifference` (2) bounds how far a
player's colours drift from even, and `maxConsecutiveSameColor` (2) their
longest run. A player at either cap has a **binding** claim that outranks the
`colorPriority` rule; below it, `pickColorOnStrongerPreference` decides whether
being more lopsided wins or the priority does, and `ignoreMildColorPrefs`
discounts the claim of someone whose colours are already even.

Both caps are bounds rather than preferences, which is how Sevilla states them —
"may not exceed", and setting either below 2 can leave it unable to pair at all.
So they also constrain **who is paired**, not only who is overruled once a board
exists: when the obvious opponent would push a capped player past their limit,
`findOpponent` looks for someone else whatever the algorithm, and ignores
`limit` while doing it — that budget is for improving colours nobody minds. The
search still stops at the end of the candidates that are equally good on
category and rematch, so it never buys colour with a worse board. A thin field
can still leave a player over cap: we are more permissive than Sevilla, which
would rather refuse to pair. Reaching for colour when nobody is at a cap remains
the colour-aware algorithm's job alone.

**Categories constrain pairing**, as the firmest of the three preferences rather
than as a filter. Category distance is the first sort key in `findOpponent`,
ahead of rematch and rank proximity, and it bounds the colour-aware look-ahead —
which never reaches past a candidate sitting in a further category. Choosing
opponents one at a time is greedy and can strand the last few players, so
`repairCategories` then trades players between boards for as long as that
strictly reduces the total breach. `categoryPairing` defaults to `adjacent` at
`categoryDistance` 1 — own category or the next one either way — and `free`
switches it off. A player with no category is never constrained, categories
being optional per season.

Because it ranks rather than filters, a field that genuinely can't be paired
inside its categories keeps a breaching board instead of losing a game: the
repair pass accepts only strictly-improving swaps, and then gives up.

The oracle is unambiguous. Across 444 games: `C-C 148`, `B-B 102`, `A-B 78`,
`A-A 63`, `B-C 53`, and **`A-C` exactly zero**. That is not a side effect of
pairing by strength, which was this file's previous claim:

- An A player sat **directly next to** a C player in the standings **378 times**
  over the season — more often than A sat next to A — and within three ranks
  1116 times. Proximity pairing would have produced A-C games constantly.
- Nor is it a width limit. The widest game played was **525 rating points**
  (C vs C), while the *closest possible* A-vs-C pairing is **202**. Thirty games
  were played at a rating-order distance of 14 or more, topping out at 24; the
  closest possible A-C is 15.

So wide gaps are acceptable inside a category or between neighbours, and
impossible across two — which only a category rule produces.

One loose end: paired players are markedly closer in **rating order**
(median gap 5, max 24) than in **Keizer-score order** (median 9, max 43). The
pairing order may not be the score at all. Deliberately not acted on yet.

Categories also drive the standings filter.

**Nothing plays a team tournament yet.** What exists is the roster: teams are
named, filled and put in board order, and that is where it stops. `is_team` is
read only by storage, serialization and guards — **the engine has no reference
to it at all** (`grep is_team src/Engine/` finds nothing). Pair a round on a team
season and you get whatever the season's pairing system does to individuals;
board numbers take no part, six boards between two teams stay six separate
games, and standings rank players rather than teams.

Worse, `categoryPairing` defaults to `adjacent` and reads `category` — which on
a team season resolves to the player's *team*. So Keizer would prefer to pair
teammates, the opposite of the point. `free` is the workaround.

So Team is refused for a **new** tournament, the same way Swiss is: both admin
selects render it disabled and `SeasonController::requireTeamPlayImplemented`
refuses `is_team: true` on create and on any switch onto it. A season already on
it stays fully editable, and turning it off is allowed — that's a move towards
something playable. Building the real thing means a pairing engine that pairs
*teams* and then expands each match into boards, which is a third shape
alongside `PerRoundPairing` and `FullSchedulePairing`.

**Teams reuse all of it.** `seasons.is_team` adds no table: a team tournament's
teams *are* its `categories`. The flag decides what the groups are called, and
the Categories tab renders as the Teams tab — one component,
`TournamentCategoriesTab`. A tournament is one or the other, never both, which is
why nothing was added alongside. Like the pairing system and the tempo, it is
fixed once the tournament leaves preparation.

**Boards.** A team plays in board order — board 1 against board 1 — so a team
season's `categories` column holds the line-up rather than a bare list of names:

```json
{ "Team A": { "1": 14, "2": 3 }, "Team B": { "1": 8, "2": 19 } }
```

One column, two readings, decided by `is_team`: `Season::$categories` is always
the list of names (`array_keys` for a team season), and `Season::$teams` is the
`TeamSheet` behind them. Nothing else changes shape, so every caller that
validates a group name against `$season->categories` reads the same thing it
always did.

`TeamSheet` (`src/Entity/ValueObjects/TeamSheet.php`) owns that JSON. It keeps
each team as an ordered list and writes the board numbers out on encode, so
**1..n with one player per board holds by construction** — no caller can leave a
gap or put two players on one board, because no caller ever names a number. Its
mutations (`place`, `without`, `reorder`, `withNames`, `withAssignments`,
`replace`) each return a new sheet, and `SeasonController::saveTeams` writes it
whole in one update.

**A team season doesn't use `season_players.category`.** Membership lives in the
sheet, and `SeasonPlayerRepository` joins the season row into every enrolment
query so `hydrate` can fill `category` and `board_number` from it. Both stay
ordinary properties on `SeasonPlayer`, so the ~30 call sites that load enrolments
and the viewer code that shows a group are untouched — a team season's player
simply reports their team where an individual season's reports their category.
`board_number` is **not** a column; it's the sheet index plus one.

The consequence to keep in mind: any write that changes team membership has to
go through the season row, not the enrolment. That's `enrollPlayer`,
`setPlayerCategory`, `assignCategories`, `setTeamBoards`, `removePlayer(s)`, and
`PlayerMergeService` — a merge repoints the ids inside the sheet, which the
enrolment repoint doesn't reach.

Joining a team takes the bottom board and leaving one gives it up; Auto Fill
rebuilds every team and orders each by rating, strongest on board 1. The order
is changed with the per-row arrows or by dropping one player onto another.

**Teams are fixed once the tournament starts** — `requireTeamsEditable` refuses
the team list, the assignments and the board order from `active` onwards, not
just from `completed`, because by then boards are published and being played.
Individual categories keep their old behaviour and stay editable while the
tournament runs; only `is_team` seasons freeze early.

`PATCH /seasons/{id}/boards` takes a team and its players **in playing order**,
never the numbers themselves: 1..n is assigned server-side, so no request can
leave a gap or put two players on one board. Auto Fill renumbers every team by
rating, which is consistent with it overriding whatever was set by hand.
Individual tournaments never set the column.

The consequence to know: everything that reads `category` still reads it. Under
Keizer that includes `categoryPairing`, which defaults to `adjacent` — so on a
team season it constrains pairing to a player's own team or the next one along,
which is backwards for a competition where teams are meant to play each other.
Latent while no team pairing exists, and the workaround is `categoryPairing: free`.

Absence recording is load-bearing under Keizer in a way it never was under
standard scoring: an absence scores `Par × OwnV`, so whether an admin marks a
missing player as club duty or personal is worth a third of their own value —
a bigger swing than most single games.

### Engine Settings

Three axes, each a JSON column on `seasons` (`pairing_settings`,
`scoring_settings`, `display_settings`), hydrated by `SettingsResolver` into
typed objects that expose `getSettingsFields()` — a schema the admin Settings
tab (`TournamentSettingsTab`, on the tournament detail page) renders the whole
form from. Adding a knob means changing its settings
class, not the frontend. Scoring freezes after the first completed round;
display never locks; pairing settings are wiped when the pairing system changes
(they're system-specific).

**`SettingsResolver::pairing()` returns null for Swiss**, which has no pairing
settings class — the endpoint sends `fields: null` and the tab renders nothing.
Manual, Keizer and both round-robins resolve. The mapping
itself lives in `pairingFor(PairingSystem, array)`, keyed by system rather than
season so `SettingsValidator` can normalise a submitted blob against the same
match arm before any season holds it.

Individual knobs live in `src/Engine/Settings/Setting/` as `SettingInterface`
objects owning their key, schema and value coercion; settings classes
**compose** them. That composition is how the engine answers whether a knob
applies: a system that derives its round count (round-robin from the roster, a
knockout from the field size) simply doesn't compose `NumberOfRounds`, and the
admin is never asked. `normalise()` never throws — `fromArray()` is also the
validation path (`SettingsValidator` round-trips through it), so bad input falls
back rather than being rejected.

`NumberOfRounds` (null = unlimited) is the first of these. Every tournament that
predates it reads as unlimited. It is **enforced**: `SettingsResolver::roundLimit()`
reads it by key, and `RoundRepository::createNextForSeason` refuses a round past
it — inside the same `forUpdate()` lock as the number it's checked against, so
two concurrent appends can't overshoot.

Round-robin composes `Legs`, `Seeding` and `AlternateColoursPerLeg`. **There is
deliberately no round count** — it is
legs × (N-1) for an even field and legs × N for an odd one — and no bye value,
because the odd player out takes the `pairing_bye` that scoring already prices.
`Legs` caps at a flat 100 because a Setting can't see the roster: what is
actually bounded is legs × field size (100 legs is fine for a two-player match
and impossible for four players), so the real ceiling belongs to the generator.

`roundLimit()` returns null for round-robin, and deliberately: the schedule is
the round set, so `RoundService::createRound` refuses a hand-made round outright
once a season with `cadence() === 'full'` has one — rather than capping at a
number. Before there is a schedule the manual path stays open, so a failed
generation can't leave the admin with no way to create a round at all.

### Round-Robin Pairing

`RoundRobinPairing` implements `FullSchedulePairing`: one call returns the whole
fixture (`POST /seasons/{id}/rounds/generate` → `RoundService::generateSchedule`),
persisted as a run of draft rounds with their games and pairing byes.

The schedule is a **FIDE Berger table** and matches the published ones
pair-for-pair and colour-for-colour — organisers check. One slot stays fixed
while the rest rotate around it; its opponent in round *r* is *k*, every other
pair is *(k+d, k−d)* around that rotation with the higher offset taking white,
and the fixed slot alternates colour by round. An odd field plays as if one more
player were present, and whoever draws that number sits out — exactly once each,
recorded as a `pairing_bye` (present, not absent). Boards are ordered by highest
seed in the pair, which is a presentation choice, not the table's own order.

Three things follow from the fixture being derived from pairing numbers:

- **Regeneration is refused once any round leaves draft.** Rebuilding rewrites
  boards from round 1, which is fine before anyone has seen them and not after.
- **The roster is locked in practice** from the moment it is generated. A late
  enrolment shifts every number after it; nothing enforces this yet beyond the
  draft rule.
- **Hand-made rounds are refused** for `cadence() === 'full'` once a schedule
  exists (see the round-limit note above).

Guards live in the engine because only it sees both the legs and the roster:
fewer than two players, and a schedule longer than 255 rounds — legs × (N-1) for
an even field and legs × N for an odd one — both throw a `ConflictException`
naming the real numbers. They run before the transaction, so a rejected
generation deletes nothing.

### Member Authentication

Members log in via email + password (not WordPress accounts):

1. Admin sends invite email with one-time link
2. Member sets password via invite link
3. Member logs in at React login form
4. System issues JWT cookie (httpOnly, Secure, SameSite=Lax)
5. JWT carries `ROLE_MEMBER` or `ROLE_ADMIN`

Login sets **two** cookies (`AuthController::setSessionCookies`):

- `scs_token` — httpOnly JWT. The only thing that authorizes anything.
- `scs_ui` — readable hint (role + player id, no PII) so the frontend knows who
  it is at first paint. Display only; nothing server-side reads it.

**Never put per-user data in the bootstrap payload** (`Assets::enqueue_frontend`
→ `window.scsBootstrap`). It is written into the page HTML, and a full-page
cache stores that HTML keyed by URL and serves it to the next visitor. The usual
protection doesn't apply here: caches skip logged-in users by spotting
`wordpress_logged_in_*`, which our members never get. Session data travels by
cookie for exactly this reason.

Admins are separate accounts in the `admins` table — see below.

### Admin Accounts

Admins are **not** WordPress users and not members. Three ways one comes to
exist, in the order they were built:

1. `wp scs create-admin` — the documented path, and unreachable on production,
   which has no convenient CLI.
2. `POST /auth/bootstrap-admin` — public, and inert the moment any admin row
   exists. It creates *one* account and can never create a second.
3. **Invitation from the Admins tab** — `POST /admins`, the only path that grows
   the list on a live site.

Invitation reuses the member invite machinery wholesale: same 7-day token
(SHA-256 hashed at rest), same `/accept-invite` page, same
`POST /auth/accept-invite`. `AuthService::acceptInvite` and `inviteTokenStatus`
check members first and then admins, so one link shape serves both and the reply
never says which kind of account a token belongs to. `admins.password_hash` is
therefore **nullable** — an invited admin has none until they follow their link,
and `AuthContextService` requires `Active`, so they can't sign in meanwhile.

**Only the first admin can invite or delete.** There is no role column and no
`created_by`, so "first" is derived: the lowest id
(`AdminRepository::firstAdminId`). Every admin sees the tab and the list; the
write routes are `$isAdmin` like any other and then narrowed again inside
`AdminController::requireSuperAdmin`, because hiding a button is not a
permission. `GET /admins` reports `is_super_admin` per row so the frontend
doesn't re-derive the rule.

This is a deliberate stopgap, not a role system. It exists because production
can't run WP-CLI and a second admin was otherwise unreachable; an invited admin
can do everything the inviter can except invite further admins.

**One address, one login.** Members and admins are separate tables with separate
passwords, and `attemptLogin` checks members first — so an address in both
resolves to the member account and leaves the admin one unreachable (its
password fails against the member hash; the member password signs them in as a
member). Nothing surfaces the collision; the admin simply can't log in.

Enforced on **both** sides, in `AuthService` rather than the controllers so a
new caller can't introduce a third way in: `assertNotAMemberAddress` guards the
two admin invite paths plus `createAdmin` — which is how both `bootstrap-admin`
and `wp scs create-admin` reach the table, so no path mints an admin without it
— and `assertNotAnAdminAddress` the two member ones. Guarding
only the admin side was not enough — a member invite can be re-sent onto an
arbitrary address, including an existing admin's, and `revokeMember` and
`resendInvite` both keep `password_hash`. Any admin could therefore have locked
the first admin out permanently, and with them the only account able to manage
admins. The real fix is one identity with roles attached, which is a much larger
change.

Note this guards new collisions only. A row pair that already shares an address
stays shadowed until one of them is changed.

**Admins have no password reset.** `initiatePasswordReset` and `resetPassword`
resolve through `MemberRepository` alone, and `admins` has no `reset_token` /
`reset_expires_at` columns — so "forgot password" mails an admin nothing. This
did not matter while every admin password was typed in by whoever ran
`wp scs create-admin`; it does now that invited admins, whose password only they
know, are the normal case. Recovery today is the first admin deleting and
re-inviting the account, which also drops its tournament-contact rows.

Removal is a **delete**, not a status flip — the row goes, along with the
account's `season_contacts` rows (no FK cascade), inside one transaction. Their
session dies with it: `AuthContextService` re-reads the account per request and
fails closed. The first admin is refused, which — since only they can delete —
also stops them deleting themselves and leaving nobody able to invite.
`AdminStatus::Revoked` remains in the enum and nothing sets it.

`GET /admins` returns **every** admin whatever the status, because the tab lists
pending invites. The tournament-contacts picker reads the same endpoint and
filters to `active` in `ContactsField` — an admin who hasn't accepted yet
shouldn't be pickable as a notification recipient.

### Tournament Lifecycle

`SeasonStatus` runs preparation → active → completed. Only two of those moves
are wired up, and they are not symmetrical.

**Starting** is the header's Start button — `PATCH /seasons/{id}` with
`status: 'active'`. `preparation ↔ active` is otherwise unvalidated and
deliberately left that way: an admin who started a tournament by mistake can put
it back and delete it (`destroy` is preparation-only).

**Completing is not a status write at all.** `PATCH /seasons/{id}` rejects
`status: 'completed'` outright, so there is exactly one way in: ticking *"Also
complete the tournament"* in the Complete-round modal, which sends
`complete_season` on `PATCH /rounds/{id}/status` and lands in
`RoundService::closeSeason` — inside the same transaction as the round's own
completion, because closing the tournament is irreversible and must not survive
scoring refusing the round. It refuses unless **every** round is complete, and
refuses a season with **no** rounds: one that never played a round was cancelled,
not finished. The frontend offers the tick only on the last round, which is the
admin's cue rather than the real condition.

**Completed is final and read-only.** There is no reopen, by decision — recovery
would be a DB edit. The freeze is enforced in three places, none of which is the
round status (a season can be completed with a draft round sitting in it, and
round status wouldn't catch a *new* round, a reopen, or a date edit):

- `RoundService::assertSeasonOpen` — from `createRound`, `generateSchedule`,
  `pairRound`, `completeRound`, `reopenRound`, and both `require*Round` helpers.
  Public, because `RoundController` writes the round date and the plain
  draft/published/finalised transitions straight through the repository.
- `SeasonController::requireOpenSeason` — the roster and category writes.
- `SeasonController::requireDisplaySettingsOnly` — `PATCH /seasons/{id}` accepts
  **only** `display_settings` on a completed tournament and rejects the whole
  request if anything rides along, so the frontend must send that key alone.

The standings columns are the single exception, and `display_settings` holds
nothing else (`StandingsDisplaySettings`): they change how the finished record is
read, not what it says.

The frontend mirrors this from `isLocked(season)` in `tournamentShared.js`,
passed down as `locked` to all five tabs. Editing apparatus is **removed** rather
than disabled — the Categories add-form goes, the Players transfer list collapses
to the roster it ended with — because there is nothing to add to a finished
record. The member absence path needs no guard: `seasonAccepts` already requires
`Active`.

### Time Control

Both `seasons` and `games` carry a `time_control` (`TimeControl` enum: blitz /
rapid / classical, `NOT NULL DEFAULT 'classical'`). A game takes the season's
value **at the moment it is paired** (`RoundService::addPairing`) rather than
reading through to the season on display, so a game keeps the tempo it was
actually played under whatever happens to the season row afterwards.

**The season's tempo is fixed once it leaves preparation**, same rule as the
pairing system: `SeasonController::update` rejects a change (the Basic details
tab disables the select and says so). Otherwise one tournament would run half
at one tempo and half at another — and a full-schedule system pairs every game
up front, so a later change wouldn't reach any of them.

Nothing can set a game's tempo independently of its season — a single blitz
board inside a classical evening isn't expressible.

### Member Home and Absence Self-Report

`/home` (member-only, `GET /me/home`) is the member's landing page after login
and **the only view that spans seasons** — every other viewer route is scoped by
the tournament switcher, which is hidden here. It shows the next pairing per
running tournament, tournaments in progress, and recently finished ones.

Everything under `/me/*` derives the player from the JWT (`pid` claim) and never
from the request; there is no `/me/{id}`. Admin accounts pass the member gate but
have no player record, so `/me/home` 404s for them and the Home tab is only
offered to accounts with a linked player.

**"I can't play this round"** (`POST`/`DELETE /me/rounds/{id}/absence`,
`RoundAbsenceService`) has two modes, decided by **whether the member is already
paired** — not by round status. An enrolment marked default absent is outside
this flow entirely (see Standing Absence below):

- not on a board — the absence is recorded outright (`Absent` +
  `ByeType::Personal`) and can be withdrawn. Under standard scoring that is
  worth nothing; **under Keizer it is not** — `personal` defaults to 0.3333 of
  the player's own value, so a member's own self-report becomes scored
  competition data. Keizer plus classical is the club's configuration and the
  absence flow is classical-only, so this is the normal case rather than a
  corner of it.
- already paired — **nothing is written**; the admins are emailed with the board
  and opponent and re-pair themselves. A member action must never mutate a
  pairing: the opponent's board would change under them.
- `finalised` / `complete` rounds are closed to both.

Status is the wrong key here: `requireEditableRound` lets pairings be edited
until a round is finalised, so a `draft` round routinely *has* pairings — draft
is when the admin builds the board. Keying off status marked paired players
absent and told the admin there was nothing to do.

A member write never overwrites an attendance row that carries a `bye_type` the
admin set (that's scored competition data); a bare status row stays overwritable.
`declinableRounds()` returns a per-round `state` — `open` / `declared` /
`notified` / `locked` — and the frontend renders from that rather than inferring
one, so the withdraw affordance can't promise what the write path will refuse.

**Putting a player on a board deletes their attendance row** — `addPairing` and
`updatePairing` drop any row that carries a `bye_type` or says `Absent`, for
whoever ends up playing. The board is the later and more explicit act: it says
they turned up after all.

Both columns have to go, because nothing reads them together. Scoring reads
`bye_type` and no game check, so a bye alongside a game is scored on top of it —
whatever the season prices that bye type at, and every type does it, an admin's
club duty as much as a member's own. Pairing reads `status` and no bye, so a
stale `Absent` left behind would drop the player from a board regenerated later.
Generated pairings never create either state (`presentPlayers` excludes
`Absent`), so this rule exists for the hand-built board.

Both writes are throttled through `RateLimiterService` (they mail every active
admin), and the paired-member path carries a one-notice-per-round marker so a
resubmit can't mail the admins again.

Classical seasons only: a rapid or blitz evening is turn-up-or-don't, whereas a
classical season turns the absence into a bye that affects scoring. The reason is
**notify-only** — it rides in the email and is never stored, so there is no column
for it. Withdrawal removes only an `Absent` + `Personal` row; an admin
re-classification is theirs to reverse.

The picker reads "Round 12 · Tue 9 Dec" once the round has a date, and degrades
to the number alone when it doesn't — undated rounds sort last rather than
dropping out. The date is set on the admin Pairings tab (`PATCH /rounds/{id}`),
which is deliberately not guarded on round status: it records the evening a
round was played, not competition data, so correcting it afterwards is a
legitimate admin fix.

### Standing Absence

An enrolment can be marked **default absent** (`season_players.default_absent`),
for the member who plays five evenings a season rather than thirty-four.
`RoundService::createRound` then writes an `Absent` + `ByeType::Personal` row for
them as each round is created, so the round opens with them already in the
Personal bye box and `presentPlayers()` leaves them out of a generated board.

**Round creation is the only trigger.** Flagging an enrolment does not backfill
rounds that already exist, and un-flagging does not clear the rows it wrote — the
admin drags the player out of the bye box, which writes `Present` and makes them
pairable again. That keeps the write out of every enrolment path and away from
completed rounds, at the cost of a stale row after a mid-season change.

It is **scored**, like any personal bye: `Par(personal) × OwnV` under Keizer, a
third of the player's own value at the club's setting. Intended — see the
absence-scoring note above.

Offered wherever `cadence() !== 'full'`. A full schedule pairs every round up
front and `generateSchedule` bypasses `createRound` entirely, so the trigger
would never fire; `SeasonController::setDefaultAbsence` refuses those seasons and
the tab isn't rendered for them. Manual and Swiss qualify despite having no
pairing engine — the row is written at round creation, not at pairing.

A flagged enrolment is **out of the member self-report flow** altogether:
`declinableRounds` skips it, and `declare` / `withdraw` refuse via `resolve()`.
Without the second guard `withdraw` would let a member clear their own standing
absence a round at a time — `isOwnDeclaration` matches exactly the row the flag
writes.

The admin **Absences tab** (`TournamentAbsencesTab`, `GET`/`PATCH
/seasons/{id}/absences`) sets it as a transfer list over the roster, and lists
the absences recorded for the round about to be played — the highest-numbered
round that isn't complete, rather than the lowest, so an unfinished previous
round doesn't hide the one being paired. Standing absences never appear in that
list; neither do declarations made after the pairings go out, which write nothing
and only mail the tournament's contacts.

### Tournament Contacts

The admins a tournament's notifications go to (`…scs_season_contacts`,
`SeasonContactService`). Edited in the admin Create-tournament dialog and the
Basic details tab; written through `POST /seasons` and `PATCH /seasons/{id}` as
`contact_admin_ids`, read back from `GET /seasons/{id}/contacts`. The picker
lists active admins from `GET /admins`.

The admin who creates a tournament is its first contact. That's a **default,
not an invariant** — the Create dialog pre-selects them (marked "you") and they
can take themselves off before saving, so `store` honours a submitted list as
it stands and only falls back to the creator when `contact_admin_ids` is absent
entirely. Nothing treats one contact as special and there is no `created_by`
column.

**An empty list means every active admin.** That's the pre-contacts behaviour,
so tournaments that predate the feature keep notifying everyone without a
backfill migration, and emptying the list can't silently switch a tournament's
notifications off. Contacts that are later revoked are filtered out at send
time, and if that empties the list the same fallback applies.

### Database Architecture

Table names are built from `SCS_TABLE_PREFIX` (`$wpdb->prefix . 'scs_'`), so
they follow the host's WordPress prefix. **Production is not `wp_`** — the live
site uses `boa_scs_*`. Always compose names with `SCS_TABLE_PREFIX`; never
hardcode `wp_scs_`.

- `…scs_seasons` — competition seasons (name, dates, pairing system,
  `time_control`, `is_team`)
- `…scs_season_players` — player enrollment (season + category + player, plus
  `default_absent` and `board_number`)
- `…scs_rounds` — competition rounds
- `…scs_games` — individual pairings/results (carries its own `time_control`)
- `…scs_attendance` — per-round presence and bye type
- `…scs_standings_snapshots` — immutable per-round standings, written on round-complete.
  Its `byes` column counts **pairing byes only**, deliberately: counting every
  bye type would make it `total rounds − games played` and carry no information
  the row doesn't already hold. It is also what `chooseBye` rations the next
  pairing bye by, where an absence has no place. Scoring is unaffected either
  way — Keizer reads `byesByPlayer` directly and prices every type
- `…scs_season_contacts` — which admins a tournament's notifications go to
- `…scs_members` — non-WordPress member accounts
- `…scs_admins` — plugin admins (invitable; `password_hash` is nullable)
- `…scs_players` — the person registry, shared across seasons

**Multiple seasons can be `active` at once** — a season also models a mid-season
tournament, so a league season and side tournaments run concurrently. There is
no "single active season" invariant: don't add a unique constraint, and note
`SeasonRepository::findActive()` returns a list. Categories are a per-season
property (`categories` column), optional — a season may run as one undivided
pool, so `season_players.category` is nullable.

Migrations live in `includes/migrations/` and are tracked in the
`scs_applied_migrations` WordPress option by their **numeric prefix**, not their
filename and not a global version number. So a number is spent the moment a
database applies it, whatever the file behind it was called: withdrawing a
migration and reusing its number means any database that ran the old one
silently skips the new one. Either take the next number, or — if the withdrawn
one never left a dev machine — drop it from that option and reuse the number. They run on
`plugins_loaded`, because the deploy flow (git pull / upload-replace) never
fires the activation hook. The `SCS_DB_VERSION` constant is currently unused.

## Development Workflow

### Local Setup

```bash
# Clone repo
git clone <repo> /path/to/club-competition-plugin
cd club-competition-plugin

# Install dependencies
composer install
npm install

# Activate plugin in WordPress
wp plugin activate club-competition-plugin

# Run database setup
wp scs migrate
```

### Common Commands

```bash
# Install/update PHP dependencies
composer install

# Install/update Node dependencies (for React build)
npm install

# Build React frontend (compiles js/ → build/). Requires Node 22 (see .nvmrc).
npm run build

# Watch mode for development
npm run start

# Lint / format the frontend
npm run lint
npm run format

# Static analysis and PHP style (run these in the Docker container — see dev/)
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix

# Apply migrations (they also run automatically on plugins_loaded)
wp scs migrate

# Create admin user
wp scs create-admin --name="Admin Name" --email="admin@example.com"

# Download the latest KNSB rating list to the server
wp scs fetch-knsb-ratings
```

Those three are the **only** registered WP-CLI commands (see
`Container::boot`). Production has no convenient CLI, so anything an admin needs
must also exist in the UI — which is why fetching KNSB ratings and importing a
season fixture both have admin dialogs.

### Build Output

`@wordpress/scripts` compiles React to `build/`:
- `build/viewer.js` / `build/viewer.css` — **the whole app**, viewer and admin
  alike (`js/app/admin/**` is part of this bundle)
- `build/viewer.asset.php` — dependency/version manifest

Enqueued by `Assets::enqueue_frontend`, wired in `src/Container.php`.

`build/admin.*` is a leftover of a second webpack entry (`js/admin/index.js`)
that renders "not built yet" and is **never enqueued** — nothing emits the
`#scs-admin` mount point. It still ships ~76 KB of duplicated CSS on every
deploy. Drop the entry, or give it a real mount; don't treat it as the admin UI.

## Important Patterns

### Strict Types

Always declare `declare(strict_types=1);` at the top of every PHP file, immediately after the opening `<?php` tag:

```php
<?php

declare(strict_types=1);

namespace SCS\Repository;
```

This enforces strict type checking for function arguments and return types, catching type errors at runtime.

### REST API Response Format

Controllers return `WP_REST_Response` with plain arrays — WordPress encodes
the JSON. Entities are turned into arrays by `SerializerService` (a
hand-rolled normalizer in `src/Services/`), never by Symfony Serializer.

```php
// In Controller (extends RestController)
return $this->ok([
    'season'  => $this->serializer->serialize($season, SerializerService::GROUP_ADMIN),
    'players' => $this->serializer->serializeMany($players),
]);
```

Field visibility is controlled by the `$group` argument
(`GROUP_PUBLIC` / `GROUP_ADMIN`), not attributes. The serializer is a
**whitelist**: each entity has a method that emits only the fields it
should expose, so secret-bearing properties (`password_hash`,
`invite_token`, `reset_token`) are never serialized. Public GET routes
serialize with `GROUP_PUBLIC`; admin-only writes use `GROUP_ADMIN`
(which adds `email`, `created_at`, etc.).

### CSRF Protection

Admin write endpoints are CSRF-protected on top of the JWT check. On login the
server issues a CSRF token (base value stored in an httpOnly `scs_csrf` cookie
via `CookieCsrfTokenStorage`; the randomized value is returned in the login
response and from `GET /auth/csrf-token`). Clients must echo that value in the
`X-SCS-CSRF-Token` header on every write; the `$isAdmin` permission callback in
`includes/RestApi.php` validates it via `CsrfTokenManager`.

**Any new admin write route must go through `$isAdmin`** (which enforces both
`ROLE_ADMIN` and the CSRF header) — don't add a write endpoint that only checks
the JWT.

### Frontend Conventions

- **Tailwind utilities are unlayered on purpose.** `css/tailwind.css` imports
  Tailwind expanded so utilities sit outside `@layer`. Production runs Hello
  Elementor + Optimizer, whose *unlayered* `h1` / `a` / `button` rules would
  otherwise beat any layered rule regardless of specificity and render the app
  half-themed. Preflight stays layered. Read the comment in that file before
  touching it — this is a real production regression, invisible locally.
- **Query keys come from `js/app/api/keys.js`.** No inline key arrays: a key
  written two ways is an invalidation that silently misses.
- **Modals go through `js/app/components/Dialog.jsx`**, which supplies dialog
  semantics, focus handling, Escape and the scroll lock. Pass `busy` while a
  write is in flight so a second confirm can't fire. Don't hand-roll a backdrop.
- **The viewer never imports from `js/app/admin/`.** It's one bundle, so it
  would work — but shared domain constants belong in `js/app/components/`
  (`game.jsx` holds the time-control labels for exactly this reason).
- **User-facing error text is authored in the frontend**, keyed on status at the
  `ApiError` layer. Backend exception messages stay detailed and specific —
  they're for logging, not for display.

### Database Access

**Only Repository classes communicate with the database.** All database queries happen in `src/Repository/`. Services, Controllers, and other classes retrieve data through repository methods — never direct DB calls.

**Avoid raw SQL queries unless impossible.** Use Doctrine DBAL's query builder and prepared statements. Always bind parameters, never string-interpolate:

```php
// Good: query builder, with the host's prefix and a bound parameter
$qb = $conn->createQueryBuilder();
$qb->select('*')->from(SCS_TABLE_PREFIX . 'players')->where('id = ?')->setParameter(0, $id);

// Bad: hardcoded prefix — production is boa_scs_, not wp_scs_
$conn->executeQuery('SELECT * FROM wp_scs_players WHERE id = ?', [$id]);

// Bad: raw SQL string interpolation (SQL injection risk)
$conn->executeQuery('SELECT * FROM ' . SCS_TABLE_PREFIX . "players WHERE id = $id");
```

Example:
```php
// Good: Service uses Repository
class PairingService {
    public function __construct(private PlayerRepository $playerRepo) {}
    public function generatePairings() {
        $players = $this->playerRepo->findBySeason($seasonId);
    }
}

// Bad: Service talks to DB directly
class PairingService {
    public function generatePairings() {
        $db->executeQuery('SELECT * FROM wp_scs_players...');
    }
}
```

### Error Handling

Use custom exceptions in `src/Exception/`:
- `NotFoundException` (404)
- `ConflictException` (409, e.g., pairing already exists)
- `ValidationException` (400, validation errors)
- `UnauthorizedException` (403)

Controllers catch and return appropriate HTTP responses.

## Deployment

### Git → SiteGround Workflow

**SiteGround has no Node.js** (Shared & Cloud plans), so `npm run build` cannot
run on the host. The compiled frontend in `build/` is therefore **committed to
git** (not gitignored) and shipped with the pull. Always rebuild and commit
`build/` before deploying any frontend change — the server only runs Composer.

```bash
# 1. Build the frontend locally and commit the artifacts
npm run build
git add build/
git commit -m "Build frontend"   # on a branch, then merge per Git Workflow

# 2. Push to GitHub
git push origin master

# 3. SSH into SiteGround
ssh user@domain.com

# 4. Pull and install PHP deps (no npm on host)
cd /wp-content/plugins/club-competition-plugin
git pull origin master
composer install

# 5. Run migrations
wp scs migrate

# 6. Clear cache (if using SG CachePress)
wp siteground-cache purge
```

**Important**: Test locally first. No staging environment — deployments go
straight to production. If you forget to rebuild + commit `build/`, the site
ships stale (or missing) frontend assets.

### Database Backups

SiteGround provides automated backups. Always verify deployments don't break existing data:
- Re-complete a round and check the standings snapshot after scoring changes
- Verify migration scripts with test data
- Check member invites still work

## Testing

**There is a test suite, but only for the engine.** `phpunit.xml` and
`tests/Unit/Engine/` cover Keizer scoring and pairing; everything else is still
verified by hand. `composer test` runs it.

The Keizer tests are fixture-driven: `tests/Unit/Engine/Scoring/Fixture/`
loads `fixtures/competition_2025_2026.json` — the club's real season, with the
scores that were actually published — and replays round 1 against it. That
matters because a wrong Keizer score is still a plausible-looking one, so
nothing else can catch a numeric drift.

Round 1 is asserted within a tolerance of ±2 points, deliberately: the ladder is
a straight ramp, so a player one rung out shifts by ~1.7, and Sevilla's opening
order can't be rebuilt now the competition file is gone. Later rounds are *not*
asserted — the fixture records games and byes but not absences, and an absence
is worth a third to two thirds of a player's own value every round they miss, so
drift accumulates with the season rather than staying put.

Otherwise verification is: `npm run lint`, `vendor/bin/phpstan`,
`vendor/bin/php-cs-fixer`, and hands-on testing in the UI. Don't write throwaway
CLI scripts to prove UI-facing behaviour — hand it over to be click-tested.

## External APIs

### KNSB Rating Sync

- **Source**: `https://schaakbond.nl/wp-content/uploads/2024/12/KLASSIEK.zip`
- **Trigger**: manual only — `wp scs fetch-knsb-ratings`, or the "Fetch KNSB
  ratings" dialog in the admin roster. **No cron is registered**, despite the
  monthly schedule this file used to claim.
- **Storage**: `KnsbRatingStore` writes the parsed list under
  `uploads/scs-knsb-<random>/`, hardened with `.htaccess` + `index.php`. It is
  personal data for ~20k non-users, so it must not sit in a web-reachable plugin
  directory.
- **Applying it**: matched on `knsb_id`, overwriting name, birth year and
  rating. Two entry points, both in `KnsbRatingSyncService`: one player from
  their Sync action, or the whole roster from the roster's "Sync KNSB ratings"
  action (`POST /players/knsb-sync`). Fetching never changes a player.
- The bulk run returns an **outcome per player** rather than throwing, so one
  name collision can't cost the admin the batch. Deliberately not transactional.
  `KnsbRatingStore::read()` memoises for the request — the list is ~20k rows and
  the loop asks for it once per player.

See `src/Services/KnsbRatingListFetcher.php`, `KnsbRatingStore.php`,
`KnsbRatingSyncService.php` and `KnsbNameNormalizer.php`.

### Email Notifications

Via `wp_mail()` (uses WP Mail SMTP plugin on production):
- Member invites
- Admin invites — same link and page, different copy
- Password resets
- Absence notices — a member saying they can't play a round

All sent from `src/Services/EmailNotificationService.php`. **There are no email
templates**: every body is a plain-text string built inline in that service.
Tournament-scoped mail (today: absence notices) goes to that tournament's
**contacts** — see below — all in one `To:`. Known gap: if `wp_mail` fails the
send is skipped silently, and for an already-published round the email is the
feature's only effect.

## Important Files

- **`club-competition-plugin.php`** — WordPress entry point, hooks registration
- **`src/Container.php`** — Symfony DI container setup
- **`includes/Database.php`** — Table creation, migrations
- **`includes/RestApi.php`** — REST route registration
- **`includes/Shortcode.php`** — `[clubcompetitie]` shortcode handler
- **`composer.json`** — PHP dependencies (PHP 8.2+ required)

## Code Style

Use standard PHP style — no spaces inside parentheses:

```php
// Correct
add_action('hook', [$class, 'method']);
$container->register('service', MyClass::class);

// Wrong
add_action( 'hook', [ $class, 'method' ] );
$container->register( 'service', MyClass::class );
```

## Git Workflow

- All commits must be authored as the human developer (pcmoreno), never as Claude or any AI identity. Before committing, verify `git config user.name` and `git config user.email` are set to the developer's identity. Claude must not appear as a contributor in the git log.
- Always work on a branch — never commit directly to `master` unless explicitly instructed.
- When updating a branch with changes from its base branch, use `git pull` (merge, not rebase).

## References

- Frontend spec / page inventory: `dev/page-inventory.md`
- Engine architecture notes: `dev/engine-architecture.md`
- Local Docker environment: `dev/docker-compose.yml` (run phpstan, php-cs-fixer
  and composer **in the container** — it has the PHP version production runs)
- Design bundle and Sevilla exports live outside the repo, in `../documents/`
- WordPress hosting: SiteGround, `schaakclubsantpoort.nl`, table prefix `boa_`.
  Composer runs on the host; a CLI session is not conveniently available, so
  don't design a workflow that depends on WP-CLI.
- REST routes: `includes/RestApi.php` is the authoritative list
