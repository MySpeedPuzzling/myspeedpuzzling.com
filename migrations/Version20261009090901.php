<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009090901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'High-frequency series: series picks on solving times (competition_series_id) and how their edition was matched (series_edition_match)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time ADD series_edition_match VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD competition_series_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93CF9987DFE FOREIGN KEY (competition_series_id) REFERENCES competition_series (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_FE83A93CF9987DFE ON puzzle_solving_time (competition_series_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93CF9987DFE');
        $this->addSql('DROP INDEX IDX_FE83A93CF9987DFE');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP series_edition_match');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP competition_series_id');
    }
}
