<?php

declare(strict_types=1);

namespace SCS\Services;

use Doctrine\DBAL\Connection;

/**
 * A narrow transaction boundary for cross-repository units of work.
 *
 * The project rule is that only repositories talk to the database. But an
 * operation that spans several repositories (a season import, a player merge)
 * needs a transaction that no single repository can own. Rather than inject the
 * full Doctrine Connection into a service — which would also hand it the
 * capability to run raw SQL — services depend on this, which exposes the
 * transaction boundary and nothing else query-capable. The rule then holds by
 * construction: no service can run a query because none holds a Connection.
 */
final class TransactionManager
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Run $work inside a transaction, committing on success and rolling back on
     * any thrown exception.
     *
     * @template T
     * @param \Closure():T $work
     * @return T
     */
    public function transactional(\Closure $work): mixed
    {
        return $this->connection->transactional($work);
    }
}
