<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819122313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle statistics: per-discipline medians (API PR 5a); backfill with myspeedpuzzling:recalculate-puzzle-statistics after deploy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_statistics ADD median_time INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD median_time_solo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD median_time_duo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD median_time_team INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_statistics DROP median_time');
        $this->addSql('ALTER TABLE puzzle_statistics DROP median_time_solo');
        $this->addSql('ALTER TABLE puzzle_statistics DROP median_time_duo');
        $this->addSql('ALTER TABLE puzzle_statistics DROP median_time_team');
    }
}
