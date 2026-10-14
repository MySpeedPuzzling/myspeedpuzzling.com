<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014043922 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE online_media_check (id UUID NOT NULL, worker_version VARCHAR(32) NOT NULL, verdict VARCHAR(16) NOT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, confidence DOUBLE PRECISION DEFAULT NULL, gaps JSONB DEFAULT \'[]\' NOT NULL, reason TEXT DEFAULT NULL, method VARCHAR(32) DEFAULT NULL, ms INT DEFAULT NULL, image_width INT DEFAULT NULL, image_height INT DEFAULT NULL, details JSONB DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, attempts INT DEFAULT 1 NOT NULL, media_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BC24AFF9EA9FDD75 ON online_media_check (media_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BC24AFF9EA9FDD758BF3A88E ON online_media_check (media_id, worker_version)');
        $this->addSql('ALTER TABLE online_media_check ADD CONSTRAINT FK_BC24AFF9EA9FDD75 FOREIGN KEY (media_id) REFERENCES online_entry_media (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE online_media_check DROP CONSTRAINT FK_BC24AFF9EA9FDD75');
        $this->addSql('DROP TABLE online_media_check');
    }
}
