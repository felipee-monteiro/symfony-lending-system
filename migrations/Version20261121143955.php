<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the due date to loans in two steps, so rows created before V2 are backfilled
 * (lent_at + 14 days) before the column becomes NOT NULL. SQLite cannot alter a
 * column in place, so the last step rebuilds the table.
 */
final class Version20261121143955 extends AbstractMigration
{
    private const string BACKFILL_DAYS = '+14 days';

    public function getDescription(): string
    {
        return 'Add loan.due_at, backfilling existing loans with lent_at + 14 days.';
    }

    public function up(Schema $schema): void
    {
        // 1. Add the column as nullable so existing rows stay valid.
        $this->addSql('ALTER TABLE loan ADD COLUMN due_at DATETIME DEFAULT NULL');

        // 2. Backfill the V1 loans before tightening the column.
        $this->addSql(sprintf(
            "UPDATE loan SET due_at = datetime(lent_at, '%s') WHERE due_at IS NULL",
            self::BACKFILL_DAYS,
        ));

        // 3. Enforce NOT NULL. SQLite has no ALTER COLUMN, so rebuild the table.
        $this->addSql('CREATE TEMPORARY TABLE __temp__loan AS SELECT id, borrower_id, lent_at, due_at, returned_at, item_id FROM loan');
        $this->addSql('DROP TABLE loan');
        $this->addSql('CREATE TABLE loan (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, borrower_id VARCHAR(255) NOT NULL, lent_at DATETIME NOT NULL, due_at DATETIME NOT NULL, returned_at DATETIME DEFAULT NULL, item_id VARCHAR(36) NOT NULL, CONSTRAINT FK_C5D30D03126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO loan (id, borrower_id, lent_at, due_at, returned_at, item_id) SELECT id, borrower_id, lent_at, due_at, returned_at, item_id FROM __temp__loan');
        $this->addSql('DROP TABLE __temp__loan');
        $this->addSql('CREATE INDEX IDX_C5D30D03126F525E ON loan (item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__loan AS SELECT id, borrower_id, lent_at, returned_at, item_id FROM loan');
        $this->addSql('DROP TABLE loan');
        $this->addSql('CREATE TABLE loan (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, borrower_id VARCHAR(255) NOT NULL, lent_at DATETIME NOT NULL, returned_at DATETIME DEFAULT NULL, item_id VARCHAR(36) NOT NULL, CONSTRAINT FK_C5D30D03126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO loan (id, borrower_id, lent_at, returned_at, item_id) SELECT id, borrower_id, lent_at, returned_at, item_id FROM __temp__loan');
        $this->addSql('DROP TABLE __temp__loan');
        $this->addSql('CREATE INDEX IDX_C5D30D03126F525E ON loan (item_id)');
    }
}
