<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250916104022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time ADD competition_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93C7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_FE83A93C7B39D312 ON puzzle_solving_time (competition_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93C7B39D312');
        $this->addSql('DROP INDEX IDX_FE83A93C7B39D312');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP competition_id');
    }
}
