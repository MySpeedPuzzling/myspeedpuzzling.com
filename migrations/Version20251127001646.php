<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251127001646 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wish_list_item (id UUID NOT NULL, remove_on_collection_add BOOLEAN DEFAULT false NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9A7FA71199E6F5DF ON wish_list_item (player_id)');
        $this->addSql('CREATE INDEX IDX_9A7FA711D9816812 ON wish_list_item (puzzle_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9A7FA71199E6F5DFD9816812 ON wish_list_item (player_id, puzzle_id)');
        $this->addSql('ALTER TABLE wish_list_item ADD CONSTRAINT FK_9A7FA71199E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE wish_list_item ADD CONSTRAINT FK_9A7FA711D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player ADD wish_list_visibility VARCHAR(255) DEFAULT \'private\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wish_list_item DROP CONSTRAINT FK_9A7FA71199E6F5DF');
        $this->addSql('ALTER TABLE wish_list_item DROP CONSTRAINT FK_9A7FA711D9816812');
        $this->addSql('DROP TABLE wish_list_item');
        $this->addSql('ALTER TABLE player DROP wish_list_visibility');
    }
}
