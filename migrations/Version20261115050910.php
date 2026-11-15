<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115050910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE import_layout (times_used INT DEFAULT 0 NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, signature VARCHAR(80) NOT NULL, name TEXT NOT NULL, headers JSONB NOT NULL, content JSONB NOT NULL, changes_known_export BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_460BD1FA99E6F5DF ON import_layout (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_460BD1FA99E6F5DFAE880141 ON import_layout (player_id, signature)');
        $this->addSql('ALTER TABLE import_layout ADD CONSTRAINT FK_460BD1FA99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_sheet ADD confirmed_by_player BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE import_layout DROP CONSTRAINT FK_460BD1FA99E6F5DF');
        $this->addSql('DROP TABLE import_layout');
        $this->addSql('ALTER TABLE data_import_sheet DROP confirmed_by_player');
    }
}
