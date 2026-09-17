<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260917134822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_round ADD slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round ADD results_link TEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX competition_round_slug_unique ON competition_round (competition_id, slug)');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD finished_later_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time RENAME COLUMN missing_pieces TO pieces_placed');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX competition_round_slug_unique');
        $this->addSql('ALTER TABLE competition_round DROP slug');
        $this->addSql('ALTER TABLE competition_round DROP results_link');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD missing_pieces INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP pieces_placed');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP finished_later_seconds');
    }
}
