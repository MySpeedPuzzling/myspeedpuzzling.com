<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117012232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition toolkit A2: round puzzle format (total | pick K of N), round puzzle position + label';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_round ADD puzzle_format VARCHAR(16) DEFAULT \'total\' NOT NULL');
        $this->addSql('ALTER TABLE competition_round ADD puzzles_to_solve SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD position SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD label VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_round DROP puzzle_format');
        $this->addSql('ALTER TABLE competition_round DROP puzzles_to_solve');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP position');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP label');
    }
}
