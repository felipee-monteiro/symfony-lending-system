<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the reservation queue. The FIFO index orders the queue deterministically and
 * the partial unique index keeps a borrower from queueing twice for the same item while
 * still allowing new reservations after a cancellation. Partial indexes are not part of
 * the Doctrine mapping, so both indexes are declared here as raw SQL.
 */
final class Version20261122101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the reservation table with FIFO and active-uniqueness indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reservation (id VARCHAR(36) NOT NULL, item_id VARCHAR(36) NOT NULL, borrower_id VARCHAR(255) NOT NULL, reserved_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, notified_at DATETIME DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_7E3A4D2E8F5A1B9C FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_reservation_fifo ON reservation (item_id, reserved_at, id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_reservation_active ON reservation (item_id, borrower_id) WHERE status IN ('pending', 'notified')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reservation');
    }
}
