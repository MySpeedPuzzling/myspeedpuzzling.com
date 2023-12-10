<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231210145335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time ADD "team" JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP players_count');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time."team" IS \'(DC2Type:puzzlers_group)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD players_count INT NOT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP "group"');
    }
}
