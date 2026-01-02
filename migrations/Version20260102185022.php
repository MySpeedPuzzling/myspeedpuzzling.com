<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102185022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create puzzle_statistics table for denormalized statistics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE puzzle_statistics (
                puzzle_id UUID NOT NULL,

                solved_times_count INT NOT NULL DEFAULT 0,
                fastest_time INT DEFAULT NULL,
                average_time INT DEFAULT NULL,
                slowest_time INT DEFAULT NULL,

                solved_times_solo_count INT NOT NULL DEFAULT 0,
                fastest_time_solo INT DEFAULT NULL,
                average_time_solo INT DEFAULT NULL,
                slowest_time_solo INT DEFAULT NULL,

                solved_times_duo_count INT NOT NULL DEFAULT 0,
                fastest_time_duo INT DEFAULT NULL,
                average_time_duo INT DEFAULT NULL,
                slowest_time_duo INT DEFAULT NULL,

                solved_times_team_count INT NOT NULL DEFAULT 0,
                fastest_time_team INT DEFAULT NULL,
                average_time_team INT DEFAULT NULL,
                slowest_time_team INT DEFAULT NULL,

                PRIMARY KEY(puzzle_id)
            )
        ');

        $this->addSql('ALTER TABLE puzzle_statistics ADD CONSTRAINT FK_puzzle_statistics_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE puzzle_statistics');
    }
}
