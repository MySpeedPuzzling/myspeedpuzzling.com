<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118014712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition toolkit v2 C2: competition_time_proposal.puzzle_choice + puzzle_choice_at_ms (the competitor\'s puzzle choice)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_time_proposal ADD puzzle_choice JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_time_proposal ADD puzzle_choice_at_ms BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_time_proposal DROP puzzle_choice');
        $this->addSql('ALTER TABLE competition_time_proposal DROP puzzle_choice_at_ms');
    }
}
