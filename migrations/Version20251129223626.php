<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251129223626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_tracking (id UUID NOT NULL, tracked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, team JSON DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, comment TEXT DEFAULT NULL, finished_puzzle_photo VARCHAR(255) DEFAULT NULL, player_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FC208F4D99E6F5DF ON puzzle_tracking (player_id)');
        $this->addSql('CREATE INDEX IDX_FC208F4DD9816812 ON puzzle_tracking (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_FC208F4DAFA9B124 ON puzzle_tracking (tracked_at)');
        $this->addSql('ALTER TABLE puzzle_tracking ADD CONSTRAINT FK_FC208F4D99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_tracking ADD CONSTRAINT FK_FC208F4DD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_tracking DROP CONSTRAINT FK_FC208F4D99E6F5DF');
        $this->addSql('ALTER TABLE puzzle_tracking DROP CONSTRAINT FK_FC208F4DD9816812');
        $this->addSql('DROP TABLE puzzle_tracking');
    }
}
