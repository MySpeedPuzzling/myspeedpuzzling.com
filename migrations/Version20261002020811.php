<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002020811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE duplicate_puzzle_signal ADD score INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal ADD reasons JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal ADD name_similarity DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal ADD weak BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE duplicate_puzzle_signal DROP score');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal DROP reasons');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal DROP name_similarity');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal DROP weak');
    }
}
