<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250905180519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add collection_item table for puzzle-collection relationships and puzzleCollectionVisibility to Player';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE collection_item (id UUID NOT NULL, comment TEXT DEFAULT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, collection_id UUID DEFAULT NULL, player_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_556C09F0514956FD ON collection_item (collection_id)');
        $this->addSql('CREATE INDEX IDX_556C09F099E6F5DF ON collection_item (player_id)');
        $this->addSql('CREATE INDEX IDX_556C09F0D9816812 ON collection_item (puzzle_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_556C09F0514956FD99E6F5DFD9816812 ON collection_item (collection_id, player_id, puzzle_id)');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F0514956FD FOREIGN KEY (collection_id) REFERENCES collection (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F099E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F0D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player ADD puzzle_collection_visibility VARCHAR(255) DEFAULT \'private\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE collection_item DROP CONSTRAINT FK_556C09F0514956FD');
        $this->addSql('ALTER TABLE collection_item DROP CONSTRAINT FK_556C09F099E6F5DF');
        $this->addSql('ALTER TABLE collection_item DROP CONSTRAINT FK_556C09F0D9816812');
        $this->addSql('DROP TABLE collection_item');
        $this->addSql('ALTER TABLE player DROP puzzle_collection_visibility');
    }
}
