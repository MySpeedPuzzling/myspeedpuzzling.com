<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109230441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PhotoStash descriptions in Postgres (photo_stash_entry) instead of .json objects';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE photo_stash_entry (token VARCHAR(32) NOT NULL, name TEXT NOT NULL, mime TEXT NOT NULL, preview BOOLEAN NOT NULL, bytes INT DEFAULT NULL, stored_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, touched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, browser_metadata JSONB DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY (token))');
        $this->addSql('CREATE INDEX IDX_3C379D7999E6F5DF ON photo_stash_entry (player_id)');
        $this->addSql('CREATE INDEX IDX_3C379D7999E6F5DF8C43597D ON photo_stash_entry (player_id, touched_at)');
        $this->addSql('ALTER TABLE photo_stash_entry ADD CONSTRAINT FK_3C379D7999E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE photo_stash_entry DROP CONSTRAINT FK_3C379D7999E6F5DF');
        $this->addSql('DROP TABLE photo_stash_entry');
    }
}
