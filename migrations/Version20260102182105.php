<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260102182105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_statistics (solved_times_count INT DEFAULT 0 NOT NULL, fastest_time INT DEFAULT NULL, average_time INT DEFAULT NULL, slowest_time INT DEFAULT NULL, solved_times_solo_count INT DEFAULT 0 NOT NULL, fastest_time_solo INT DEFAULT NULL, average_time_solo INT DEFAULT NULL, slowest_time_solo INT DEFAULT NULL, solved_times_duo_count INT DEFAULT 0 NOT NULL, fastest_time_duo INT DEFAULT NULL, average_time_duo INT DEFAULT NULL, slowest_time_duo INT DEFAULT NULL, solved_times_team_count INT DEFAULT 0 NOT NULL, fastest_time_team INT DEFAULT NULL, average_time_team INT DEFAULT NULL, slowest_time_team INT DEFAULT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('CREATE INDEX IDX_9FC82DAEF6012350 ON puzzle_statistics (solved_times_count)');
        $this->addSql('CREATE INDEX IDX_9FC82DAEBC7ADEC0 ON puzzle_statistics (fastest_time)');
        $this->addSql('ALTER TABLE puzzle_statistics ADD CONSTRAINT FK_9FC82DAED9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD puzzlers_count SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD puzzling_type VARCHAR(255) DEFAULT \'solo\' NOT NULL');
        $this->addSql('CREATE INDEX IDX_FE83A93C1E0613E4 ON puzzle_solving_time (puzzlers_count)');
        $this->addSql('CREATE INDEX IDX_FE83A93C58DDC291 ON puzzle_solving_time (puzzling_type)');

        // Populate from existing data
        $this->addSql("
            UPDATE puzzle_solving_time
            SET
                puzzlers_count = CASE
                    WHEN team IS NULL THEN 1
                    ELSE json_array_length(team->'puzzlers')
                END,
                puzzling_type = CASE
                    WHEN team IS NULL THEN 'solo'
                    WHEN json_array_length(team->'puzzlers') = 2 THEN 'duo'
                    ELSE 'team'
                END
        ");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_statistics DROP CONSTRAINT FK_9FC82DAED9816812');
        $this->addSql('DROP TABLE puzzle_statistics');
        $this->addSql('DROP INDEX IDX_FE83A93C1E0613E4');
        $this->addSql('DROP INDEX IDX_FE83A93C58DDC291');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP puzzlers_count');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP puzzling_type');
    }
}
