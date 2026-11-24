<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120220743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the item and loan tables for the lending aggregate.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE item (id VARCHAR(36) NOT NULL, title VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE loan (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, borrower_id VARCHAR(255) NOT NULL, lent_at DATETIME NOT NULL, returned_at DATETIME DEFAULT NULL, item_id VARCHAR(36) NOT NULL, CONSTRAINT FK_C5D30D03126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C5D30D03126F525E ON loan (item_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE item');
        $this->addSql('DROP TABLE loan');
    }
}
