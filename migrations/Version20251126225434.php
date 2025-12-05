<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251126225434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unsolved_puzzles_visibility column to player table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD unsolved_puzzles_visibility VARCHAR(255) DEFAULT \'private\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP unsolved_puzzles_visibility');
    }
}
