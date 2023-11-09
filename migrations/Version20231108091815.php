<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231108091815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stopwatch ADD puzzle_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE stopwatch ADD laps JSON NOT NULL');
        $this->addSql('ALTER TABLE stopwatch ADD status VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE stopwatch ALTER player_id SET NOT NULL');
        $this->addSql('COMMENT ON COLUMN stopwatch.puzzle_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.laps IS \'(DC2Type:laps[])\'');
        $this->addSql('ALTER TABLE stopwatch ADD CONSTRAINT FK_E7C8F822D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_E7C8F822D9816812 ON stopwatch (puzzle_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE stopwatch DROP CONSTRAINT FK_E7C8F822D9816812');
        $this->addSql('DROP INDEX IDX_E7C8F822D9816812');
        $this->addSql('ALTER TABLE stopwatch DROP puzzle_id');
        $this->addSql('ALTER TABLE stopwatch DROP laps');
        $this->addSql('ALTER TABLE stopwatch DROP status');
        $this->addSql('ALTER TABLE stopwatch ALTER player_id DROP NOT NULL');
    }
}
