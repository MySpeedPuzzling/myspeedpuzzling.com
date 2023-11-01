<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231101130423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT fk_fe83a93cca792c6b');
        $this->addSql('DROP INDEX idx_fe83a93cca792c6b');
        $this->addSql('ALTER TABLE puzzle_solving_time RENAME COLUMN added_by_user_id TO player_id');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93C99E6F5DF FOREIGN KEY (player_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_FE83A93C99E6F5DF ON puzzle_solving_time (player_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93C99E6F5DF');
        $this->addSql('DROP INDEX IDX_FE83A93C99E6F5DF');
        $this->addSql('ALTER TABLE puzzle_solving_time RENAME COLUMN player_id TO added_by_user_id');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT fk_fe83a93cca792c6b FOREIGN KEY (added_by_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_fe83a93cca792c6b ON puzzle_solving_time (added_by_user_id)');
    }
}
