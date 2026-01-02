<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102185021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add puzzlers_count and puzzling_type columns to puzzle_solving_time for statistics optimization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_solving_time ADD puzzlers_count SMALLINT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD puzzling_type VARCHAR(10) NOT NULL DEFAULT \'solo\'');

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
        $this->addSql('ALTER TABLE puzzle_solving_time DROP puzzlers_count');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP puzzling_type');
    }
}
