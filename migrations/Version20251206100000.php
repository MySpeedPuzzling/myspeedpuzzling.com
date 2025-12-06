<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251206100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add solved_puzzles_visibility column to player table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD solved_puzzles_visibility VARCHAR(255) DEFAULT \'private\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP solved_puzzles_visibility');
    }
}
