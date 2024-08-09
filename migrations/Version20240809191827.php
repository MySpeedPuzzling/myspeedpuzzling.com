<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240809191827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE wjpc_participant (id UUID NOT NULL, name VARCHAR(255) NOT NULL, country VARCHAR(255) NOT NULL, year2023_rank INT DEFAULT NULL, year2022_rank INT DEFAULT NULL, player_id UUID DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_40B4229999E6F5DF ON wjpc_participant (player_id)');
        $this->addSql('ALTER TABLE wjpc_participant ADD CONSTRAINT FK_40B4229999E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE wjpc_participant DROP CONSTRAINT FK_40B4229999E6F5DF');
        $this->addSql('DROP TABLE wjpc_participant');
    }
}
