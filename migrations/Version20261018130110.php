<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018130110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_image_analysis (id UUID NOT NULL, analyzer VARCHAR(32) NOT NULL, analyzer_version VARCHAR(50) NOT NULL, image_fingerprint VARCHAR(64) NOT NULL, result JSONB NOT NULL, analyzed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_723BA7D2D9816812 ON puzzle_image_analysis (puzzle_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_723BA7D2D9816812D4508325 ON puzzle_image_analysis (puzzle_id, analyzer)');
        $this->addSql('ALTER TABLE puzzle_image_analysis ADD CONSTRAINT FK_723BA7D2D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_image_analysis DROP CONSTRAINT FK_723BA7D2D9816812');
        $this->addSql('DROP TABLE puzzle_image_analysis');
    }
}
