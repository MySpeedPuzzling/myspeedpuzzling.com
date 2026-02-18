<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216235838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dismissed_hint (id UUID NOT NULL, type VARCHAR(255) NOT NULL, dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2611CDFA99E6F5DF ON dismissed_hint (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2611CDFA99E6F5DF8CDE5729 ON dismissed_hint (player_id, type)');
        $this->addSql('ALTER TABLE dismissed_hint ADD CONSTRAINT FK_2611CDFA99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dismissed_hint DROP CONSTRAINT FK_2611CDFA99E6F5DF');
        $this->addSql('DROP TABLE dismissed_hint');
    }
}
