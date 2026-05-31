# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Club Competition Manager** is a WordPress plugin that replaces desktop-based chess competition management (Sevilla software) with a web application. It manages pairings, standings, and results for Schaakclub Santpoort's internal chess competitions.

### Key Goals
- Live competition viewer with standings, cross-tables, player stats
- Admin interface for round management, pairing generation, result entry
- Keizer pairing system (automatic + manual override)
- KNSB rating integration (Dutch chess federation)
- Member invitations and authentication (no WordPress account required)
- Email notifications and PDF generation

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
| Database | MySQL 5.7+ via Doctrine DBAL | Custom tables prefixed with `wp_scs_` |
| REST API | WordPress REST API | Custom endpoints at `/wp-json/scs/v1/` |
| Authentication | Symfony Security + lcobucci/jwt | JWT in httpOnly cookies, not localStorage |
| Validation | Symfony Validator | Input validation on all DTOs |
| Serialization | Hand-rolled `SerializerService` | Entity → array; visibility via groups |
| Frontend | React | Embedded via shortcode `[clubcompetitie]` |
| PDF Generation | dompdf | Server-side pairing sheet rendering |
| Email | WordPress wp_mail | Invite notifications, round publishing |

### API Architecture

**Frontend → Backend only.** React frontend communicates exclusively with the local REST API (`/wp-json/scs/v1/`). All external API calls (KNSB ratings, Lichess, etc.) are handled by the PHP backend. The frontend never makes direct calls to external services.

### Folder Structure Logic

```
src/
├── Entity/           Data models (Player, Season, Round, Game, etc.)
├── Repository/       Database access layer (queries, CRUD operations)
├── Services/         Business logic (PairingService, ScoringService, etc.)
├── Security/Auth/    Authentication (JwtAuthenticator, MemberProvider, AdminProvider)
├── Controller/       REST API controllers (expose Services to HTTP)
├── Exception/        Custom exceptions (NotFoundException, ConflictException, etc.)
└── Command/          WP-CLI commands (admin creation, imports, KNSB sync)

includes/            WordPress integration (database schema, REST routes, shortcode)
js/                  React frontend (viewer/ for public, admin/ for management)
config/              Symfony DI service definitions
tests/               Unit and integration tests
```

## Key Concepts

### Keizer Pairing System

The plugin implements the **Keizer rating system** for Swiss-style pairings:

- Players paired by **Keizer score proximity** (not Elo)
- **Category preference**: same-category pairings before cross-category
- **Color balance**: player with fewer white games gets white
- **No repeat pairings** within season (when possible)
- **Odd count**: lowest-ranked player gets bye
- **Retroactive recalculation**: scores recalculate after every round as opponent rankings shift (this is Keizer's defining feature)

See `src/Round/Pairing/KeizerEngine.php` for implementation.

### Member Authentication

Members log in via email + password (not WordPress accounts):

1. Admin sends invite email with one-time link
2. Member sets password via invite link
3. Member logs in at React login form
4. System issues JWT cookie (httpOnly, Secure, SameSite=Lax)
5. JWT carries `ROLE_MEMBER` or `ROLE_ADMIN`

Admins are separate (created via WP-CLI, stored in `wp_scs_admins` table).

### Database Architecture

All custom tables prefixed `wp_scs_`:
- `wp_scs_seasons` — competition seasons
- `wp_scs_season_players` — player enrollment (season + category + player)
- `wp_scs_rounds` — competition rounds
- `wp_scs_games` — individual pairings/results
- `wp_scs_rankings` — score snapshots (recalculated per round)
- `wp_scs_members` — non-WordPress member accounts
- `wp_scs_admins` — plugin admins

**Multiple seasons can be `active` at once** — a season also models a mid-season
tournament, so a league season and side tournaments run concurrently. There is
no "single active season" invariant: don't add a unique constraint, and note
`SeasonRepository::findActive()` returns a list. Categories are a per-season
property (`categories` column), optional — a season may run as one undivided
pool, so `season_players.category` is nullable.

Migrations tracked via `scs_db_version` WordPress option.

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

# Build React frontend (compiles js/ → build/)
npm run build

# Watch mode for development
npm run start

# Run tests
vendor/bin/phpunit

# Run single test file
vendor/bin/phpunit tests/Unit/Services/ScoringServiceTest.php

# Create admin user (WP-CLI)
wp scs create-admin --name="Admin Name" --email="admin@example.com"

# Import data from Sevilla export
wp scs import path/to/export.csv

# Sync KNSB ratings (manual trigger, also runs via monthly cron)
wp scs sync-knsb

# Seed test data (scrapes live club website)
wp scs seed
```

### Build Output

`@wordpress/scripts` compiles React to `build/`:
- `build/viewer.js` — public/member viewer app
- `build/admin.js` — admin management UI (embedded in viewer)
- `build/viewer.asset.php` / `build/admin.asset.php` — dependency/version manifests

Enqueued in `club-competition-plugin.php`.

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

### Database Access

**Only Repository classes communicate with the database.** All database queries happen in `src/Repository/`. Services, Controllers, and other classes retrieve data through repository methods — never direct DB calls.

**Avoid raw SQL queries unless impossible.** Use Doctrine DBAL's query builder and prepared statements. Always bind parameters, never string-interpolate:

```php
// Good: prepared statement with parameter binding
$conn->executeQuery('SELECT * FROM wp_scs_players WHERE id = ?', [$id]);

// Good: query builder
$qb = $conn->createQueryBuilder();
$qb->select('*')->from('wp_scs_players')->where('id = ?');

// Bad: raw SQL string interpolation (SQL injection risk)
$conn->executeQuery("SELECT * FROM wp_scs_players WHERE id = $id");
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
git push origin main

# 3. SSH into SiteGround
ssh user@domain.com

# 4. Pull and install PHP deps (no npm on host)
cd /wp-content/plugins/club-competition-plugin
git pull origin main
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
- Test Keizer recalculation after scoring changes
- Verify migration scripts with test data
- Check member invites still work

## Testing

- **Unit tests**: `tests/Unit/` (test Services, Repositories in isolation)
- **Integration tests**: `tests/Integration/` (test with real database)
- Database fixtures in `tests/fixtures/`

Run with `vendor/bin/phpunit`.

## External APIs

### KNSB Rating Sync

- **Source**: `https://schaakbond.nl/wp-content/uploads/2024/12/KLASSIEK.zip`
- **Schedule**: Monthly via WordPress cron (runs on the 2nd)
- **Manual trigger**: `wp scs sync-knsb`
- **Update**: Matches players by `knsb_id` field, updates `knsb_elo`

See `src/Knsb/Service/KnsbSyncService.php`.

### Email Notifications

Via `wp_mail()` (uses WP Mail SMTP plugin on production):
- Member invites
- Round pairings published
- Password resets

Template rendering in `src/Shared/Notification/WpMailNotificationService.php`.

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

- Implementation Plan: `/documents/club-competition-plugin-plan (1).md`
- WordPress Hosting: SiteGround (WP-CLI + Composer available)
- REST API Docs: See implementation plan for full endpoint list
