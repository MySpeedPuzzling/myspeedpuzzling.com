<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117175553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant_round ADD result_missing_pieces SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_standing ADD penalise_finished_missing BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_team ADD result_missing_pieces SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant_round DROP result_missing_pieces');
        $this->addSql('ALTER TABLE competition_standing DROP penalise_finished_missing');
        $this->addSql('ALTER TABLE competition_team DROP result_missing_pieces');
    }
}
