<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519224639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stopwatch_stopped_at to competition_round so stopped time freezes instead of growing on every page load.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_round ADD stopwatch_stopped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_round DROP stopwatch_stopped_at');
    }
}
