<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925161855 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD approved_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD CONSTRAINT FK_22A6DFDF2D234F6A FOREIGN KEY (approved_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_22A6DFDF2D234F6A ON puzzle (approved_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle DROP CONSTRAINT FK_22A6DFDF2D234F6A');
        $this->addSql('DROP INDEX IDX_22A6DFDF2D234F6A');
        $this->addSql('ALTER TABLE puzzle DROP approved_at');
        $this->addSql('ALTER TABLE puzzle DROP approved_by_id');
    }
}
