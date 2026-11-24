<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the append-only renewal table. The loan keeps its original period; each
 * renewal is a new row pointing at the loan.
 */
final class Version20261121144511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the loan_renewal table (append-only due date extensions).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE loan_renewal (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, due_at DATETIME NOT NULL, renewed_at DATETIME NOT NULL, loan_id INTEGER NOT NULL, CONSTRAINT FK_D34BC9BCCE73868F FOREIGN KEY (loan_id) REFERENCES loan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D34BC9BCCE73868F ON loan_renewal (loan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE loan_renewal');
    }
}
