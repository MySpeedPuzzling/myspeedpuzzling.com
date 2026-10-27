<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027171430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finished-photo analyses: the image pipeline\'s measurements of players\' photos of the finished puzzle (docs/features/insights/finished-photos.md)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE finished_photo_analysis (id UUID NOT NULL, analyzer VARCHAR(32) NOT NULL, analyzer_version VARCHAR(50) NOT NULL, photo_fingerprint VARCHAR(64) NOT NULL, image_fingerprint VARCHAR(64) NOT NULL, result JSONB NOT NULL, analyzed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, solving_time_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CA3D74B1D6B05C60 ON finished_photo_analysis (solving_time_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CA3D74B1D4508325D6B05C60 ON finished_photo_analysis (analyzer, solving_time_id)');
        $this->addSql('ALTER TABLE finished_photo_analysis ADD CONSTRAINT FK_CA3D74B1D6B05C60 FOREIGN KEY (solving_time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE finished_photo_analysis DROP CONSTRAINT FK_CA3D74B1D6B05C60');
        $this->addSql('DROP TABLE finished_photo_analysis');
    }
}
