<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240408184034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE badge (id UUID NOT NULL, player_id UUID NOT NULL, type VARCHAR(255) NOT NULL, earned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FEF0481D99E6F5DF ON badge (player_id)');
        $this->addSql('COMMENT ON COLUMN badge.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN badge.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN badge.earned_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE badge ADD CONSTRAINT FK_FEF0481D99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE badge DROP CONSTRAINT FK_FEF0481D99E6F5DF');
        $this->addSql('DROP TABLE badge');
    }
}
