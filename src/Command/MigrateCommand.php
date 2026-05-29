<?php

declare(strict_types=1);

namespace SCS\Command;

use SCS\includes\Database;

class MigrateCommand {

    public function __invoke( array $args, array $assoc_args ): void {
        Database::migrate();
        \WP_CLI::success( 'Migrations completed.' );
    }
}
