<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260405133339 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant ADD external_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD source VARCHAR(255) DEFAULT \'imported\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant DROP external_id');
        $this->addSql('ALTER TABLE competition_participant DROP deleted_at');
        $this->addSql('ALTER TABLE competition_participant DROP source');
    }
}
