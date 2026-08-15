# Club Competition Manager

A WordPress plugin for managing chess competition pairings, standings, and results for Schaakclub Santpoort.

## Features

- Live competition viewer with standings and player stats
- Admin interface for round management, pairing generation, and result entry
- Keizer and round-robin pairing, with manual override
- KNSB rating integration (Dutch chess federation)
- Member accounts and invitations, separate from WordPress users
- Email notifications for invites, password resets and absences

## Requirements

- WordPress 5.0+
- PHP 8.2+
- MySQL 5.7+
- Composer (for dependency management)

## Installation

Build a zip with `bin/package.sh` and upload it in wp-admin under Plugins → Add
New → Upload Plugin, choosing "Replace current with uploaded". Migrations run on
`plugins_loaded`, so there is nothing to run afterwards.

## Development

### Setup

```bash
git clone <repo-url> /path/to/club-competition-plugin
cd /path/to/club-competition-plugin
composer install
npm install
```

### Build Frontend

```bash
npm run build
```

### Local Testing

`dev/docker-compose.yml` brings up WordPress, MySQL and Mailpit. Run PHP tooling
(`phpunit`, `phpstan`, `php-cs-fixer`) inside that container — it has the PHP
version production runs.

## Architecture

- **PHP Backend**: Symfony components for validation, DI and CSRF
- **Database**: MySQL with Doctrine DBAL
- **REST API**: WordPress REST API with custom endpoints
- **Frontend**: React-based viewer and admin interface, one bundle
- **Email**: WordPress wp_mail integration

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Plugin Runtime | PHP (WordPress plugin API) |
| Database | MySQL via Doctrine DBAL |
| REST API | WordPress REST API |
| Auth | lcobucci/jwt (httpOnly JWT cookie) + Symfony CSRF |
| Validation | Symfony Validator |
| Serialization | Hand-rolled `SerializerService` |
| DI Container | Symfony DependencyInjection |
| Frontend | React 18 via `@wordpress/element` |
| Styling | Tailwind v4 |

## License

GPL-2.0-or-later

## Author

Paulo Moreno

## Snapshots

<img width="1487" height="957" alt="image" src="https://github.com/user-attachments/assets/ee2b636b-513d-4352-b9b8-e17ad83ab174" />
<img width="1438" height="1221" alt="image" src="https://github.com/user-attachments/assets/730e5e92-c31e-41b9-a714-216cf042ccf1" />
<img width="1306" height="1088" alt="image" src="https://github.com/user-attachments/assets/1aec5021-aea3-4e27-a926-c086c9aeb248" />
<img width="1221" height="1300" alt="image" src="https://github.com/user-attachments/assets/69edc0d0-caec-486e-b64f-2df3c651e132" />




