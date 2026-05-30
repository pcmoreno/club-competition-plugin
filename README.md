# Club Competition Manager

A WordPress plugin for managing chess competition pairings, standings, and results for Schaakclub Santpoort.

## Features

- Live competition viewer with standings, cross-tables, and player stats
- Admin interface for round management, pairing generation, and result entry
- Keizer pairing system with manual override capability
- KNSB rating integration (Dutch chess federation)
- Member invitations and authentication
- Email notifications
- PDF generation for pairings

## Requirements

- WordPress 5.0+
- PHP 8.2+
- MySQL 5.7+
- Composer (for dependency management)

## Installation

1. Clone the repository into `/wp-content/plugins/club-competition-plugin/`
2. Run `composer install` to install PHP dependencies
3. Activate the plugin in WordPress admin
4. Run database migrations: `wp scs migrate`

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

See `/docs/local-development.md` for detailed setup instructions.

## Architecture

- **PHP Backend**: Symfony components for validation, DI, authentication
- **Database**: MySQL with Doctrine DBAL
- **REST API**: WordPress REST API with custom endpoints
- **Frontend**: React-based viewer and admin interface
- **Email**: WordPress wp_mail integration
- **PDF**: dompdf for pairing sheets

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Plugin Runtime | PHP (WordPress plugin API) |
| Database | MySQL via Doctrine DBAL |
| REST API | WordPress REST API |
| Auth | Symfony Security + lcobucci/jwt (JWT cookie + CSRF) |
| Validation | Symfony Validator |
| Serialization | Hand-rolled `SerializerService` |
| DI Container | Symfony DependencyInjection |
| Frontend | React |
| PDF | dompdf |

## License

GPL-2.0-or-later

## Author

Paulo Moreno
