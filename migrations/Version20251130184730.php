<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251130184730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate puzzle_tracking data to puzzle_solving_time and drop puzzle_tracking table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT INTO puzzle_solving_time (
                id,
                seconds_to_solve,
                player_id,
                puzzle_id,
                tracked_at,
                verified,
                team,
                finished_at,
                comment,
                finished_puzzle_photo,
                first_attempt,
                competition_round_id,
                competition_id,
                missing_pieces,
                qualified
            )
            SELECT
                id,
                NULL,
                player_id,
                puzzle_id,
                tracked_at,
                false,
                team,
                finished_at,
                comment,
                finished_puzzle_photo,
                false,
                NULL,
                NULL,
                NULL,
                NULL
            FROM puzzle_tracking
        SQL);

        $this->addSql('DROP TABLE puzzle_tracking');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE puzzle_tracking (
                id UUID NOT NULL,
                player_id UUID NOT NULL,
                puzzle_id UUID NOT NULL,
                tracked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                team JSON DEFAULT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                comment TEXT DEFAULT NULL,
                finished_puzzle_photo VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX IDX_puzzle_tracking_tracked_at ON puzzle_tracking (tracked_at)');
        $this->addSql('ALTER TABLE puzzle_tracking ADD CONSTRAINT FK_puzzle_tracking_player_id FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_tracking ADD CONSTRAINT FK_puzzle_tracking_puzzle_id FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<SQL
            INSERT INTO puzzle_tracking (
                id,
                player_id,
                puzzle_id,
                tracked_at,
                team,
                finished_at,
                comment,
                finished_puzzle_photo
            )
            SELECT
                id,
                player_id,
                puzzle_id,
                tracked_at,
                team,
                finished_at,
                comment,
                finished_puzzle_photo
            FROM puzzle_solving_time
            WHERE seconds_to_solve IS NULL
        SQL);

        $this->addSql('DELETE FROM puzzle_solving_time WHERE seconds_to_solve IS NULL');

        $this->addSql('COMMENT ON COLUMN puzzle_tracking.tracked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_tracking.finished_at IS \'(DC2Type:datetime_immutable)\'');
    }
}
