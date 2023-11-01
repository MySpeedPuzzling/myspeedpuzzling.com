<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231101125430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE manufacturer (id UUID NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN manufacturer.id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE puzzle (id UUID NOT NULL, manufacturer_id UUID DEFAULT NULL, added_by_user_id UUID DEFAULT NULL, pieces_count INT NOT NULL, name VARCHAR(255) NOT NULL, approved BOOLEAN NOT NULL, alternative_name VARCHAR(255) DEFAULT NULL, identification_number VARCHAR(255) DEFAULT NULL, ean VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_22A6DFDFA23B42D ON puzzle (manufacturer_id)');
        $this->addSql('CREATE INDEX IDX_22A6DFDFCA792C6B ON puzzle (added_by_user_id)');
        $this->addSql('COMMENT ON COLUMN puzzle.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle.manufacturer_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle.added_by_user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE puzzle_solving_time (id UUID NOT NULL, added_by_user_id UUID NOT NULL, puzzle_id UUID NOT NULL, seconds_to_solve INT NOT NULL, players_count INT NOT NULL, comment VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FE83A93CCA792C6B ON puzzle_solving_time (added_by_user_id)');
        $this->addSql('CREATE INDEX IDX_FE83A93CD9816812 ON puzzle_solving_time (puzzle_id)');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.added_by_user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.puzzle_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE stopwatch (id UUID NOT NULL, player_id UUID DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E7C8F82299E6F5DF ON stopwatch (player_id)');
        $this->addSql('COMMENT ON COLUMN stopwatch.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, name VARCHAR(255) NOT NULL, country VARCHAR(255) DEFAULT NULL, city VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN "user".id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL, sess_data BYTEA NOT NULL, sess_lifetime INT NOT NULL, sess_time INT NOT NULL, PRIMARY KEY(sess_id))');
        $this->addSql('CREATE INDEX sess_lifetime_idx ON sessions (sess_lifetime)');
        $this->addSql('ALTER TABLE puzzle ADD CONSTRAINT FK_22A6DFDFA23B42D FOREIGN KEY (manufacturer_id) REFERENCES manufacturer (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle ADD CONSTRAINT FK_22A6DFDFCA792C6B FOREIGN KEY (added_by_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93CCA792C6B FOREIGN KEY (added_by_user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93CD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stopwatch ADD CONSTRAINT FK_E7C8F82299E6F5DF FOREIGN KEY (player_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE puzzle DROP CONSTRAINT FK_22A6DFDFA23B42D');
        $this->addSql('ALTER TABLE puzzle DROP CONSTRAINT FK_22A6DFDFCA792C6B');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93CCA792C6B');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93CD9816812');
        $this->addSql('ALTER TABLE stopwatch DROP CONSTRAINT FK_E7C8F82299E6F5DF');
        $this->addSql('DROP TABLE manufacturer');
        $this->addSql('DROP TABLE puzzle');
        $this->addSql('DROP TABLE puzzle_solving_time');
        $this->addSql('DROP TABLE stopwatch');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE sessions');
    }
}
