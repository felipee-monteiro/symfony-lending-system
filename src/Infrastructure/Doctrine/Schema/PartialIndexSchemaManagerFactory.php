<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\SchemaManagerFactory;
use Doctrine\DBAL\Schema\SQLiteSchemaManager;

/**
 * DBAL 4 does not report partial index predicates for SQLite, so a hand-written
 * `CREATE UNIQUE INDEX ... WHERE ...` looks like a different (full) index to the
 * schema comparator and `doctrine:schema:validate` reports it as out of sync.
 *
 * This factory keeps the default behaviour for every other platform.
 */
final class PartialIndexSchemaManagerFactory implements SchemaManagerFactory
{
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        $manager = $connection->getDatabasePlatform()->createSchemaManager($connection);

        if ($manager instanceof SQLiteSchemaManager && ! $manager instanceof PartialIndexSQLiteSchemaManager) {
            return new PartialIndexSQLiteSchemaManager($connection, $connection->getDatabasePlatform());
        }

        return $manager;
    }
}
