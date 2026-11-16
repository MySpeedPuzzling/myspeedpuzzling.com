<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116192010 extends AbstractMigration
{
    public function getDescription(): string
    {
        // docs/features/event-results-import/results-import-page.md: a results source names its format - every source
        // registered so far is Sarah Does Puzzles' (the constant default fills them, no table rewrite)
        return 'Results sources (event_results_sheet) get a format, existing ones sarah_does_puzzles';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_results_sheet ADD format VARCHAR(50) DEFAULT \'sarah_does_puzzles\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_results_sheet DROP format');
    }
}
