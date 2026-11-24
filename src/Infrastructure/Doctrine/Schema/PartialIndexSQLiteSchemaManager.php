<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Schema;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\SQLiteSchemaManager;
use Doctrine\DBAL\Schema\Table;

/**
 * Restores the WHERE predicate that SQLite stores in sqlite_master but DBAL does not
 * introspect. Without it, partial indexes and their Doctrine mappings never compare equal.
 */
final class PartialIndexSQLiteSchemaManager extends SQLiteSchemaManager
{
    /** @return list<Table> */
    public function listTables(): array
    {
        return array_map(
            $this->withPartialIndexPredicates(...),
            parent::listTables(),
        );
    }

    private function withPartialIndexPredicates(Table $table): Table
    {
        $predicates = $this->fetchPartialIndexPredicates($table->getName());

        if ($predicates === []) {
            return $table;
        }

        $editor  = $table->edit();
        $changed = false;

        foreach ($table->getIndexes() as $name => $index) {
            if (! isset($predicates[$name]) || $index->getPredicate() !== null) {
                continue;
            }

            $editor->dropIndexByUnquotedName($name);
            $editor->addIndex(
                Index::editor()
                    ->setUnquotedName($name)
                    ->setUnquotedColumnNames(...$index->getColumns())
                    ->setType($index->isUnique() ? IndexType::UNIQUE : IndexType::REGULAR)
                    ->setPredicate($predicates[$name]),
            );
            $changed = true;
        }

        return $changed ? $editor->create() : $table;
    }

    /** @return array<string, string> lowercased index name => WHERE predicate */
    private function fetchPartialIndexPredicates(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql LIKE '% WHERE %'",
            [$table],
        );

        $predicates = [];

        foreach ($rows as $row) {
            if (preg_match('/\bWHERE\b\s+(.+)$/is', (string) $row['sql'], $matches) !== 1) {
                continue;
            }

            $predicates[strtolower((string) $row['name'])] = trim($matches[1]);
        }

        return $predicates;
    }
}
