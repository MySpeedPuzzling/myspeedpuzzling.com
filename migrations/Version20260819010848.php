<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819010848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle picker PR 4: per-player display mode of collection pages (off | times | times_predictions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD collection_display_mode VARCHAR(255) DEFAULT \'off\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP collection_display_mode');
    }
}
