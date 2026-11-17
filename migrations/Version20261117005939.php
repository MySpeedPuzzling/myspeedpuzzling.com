<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117005939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_display (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, name VARCHAR(100) NOT NULL, token VARCHAR(22) NOT NULL, locale VARCHAR(5) NOT NULL, design JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, design_version INT DEFAULT 1 NOT NULL, follow_mode VARCHAR(16) NOT NULL, follow_round_ids JSONB DEFAULT NULL, uses_live_results BOOLEAN DEFAULT false NOT NULL, uses_results BOOLEAN DEFAULT false NOT NULL, competition_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, pinned_round_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1364B20D5F37A13B ON competition_display (token)');
        $this->addSql('CREATE INDEX IDX_1364B20D7B39D312 ON competition_display (competition_id)');
        $this->addSql('CREATE INDEX IDX_1364B20DB03A8386 ON competition_display (created_by_id)');
        $this->addSql('CREATE INDEX IDX_1364B20D513453C4 ON competition_display (pinned_round_id)');
        $this->addSql('ALTER TABLE competition_display ADD CONSTRAINT FK_1364B20D7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_display ADD CONSTRAINT FK_1364B20DB03A8386 FOREIGN KEY (created_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_display ADD CONSTRAINT FK_1364B20D513453C4 FOREIGN KEY (pinned_round_id) REFERENCES competition_round (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_display DROP CONSTRAINT FK_1364B20D7B39D312');
        $this->addSql('ALTER TABLE competition_display DROP CONSTRAINT FK_1364B20DB03A8386');
        $this->addSql('ALTER TABLE competition_display DROP CONSTRAINT FK_1364B20D513453C4');
        $this->addSql('DROP TABLE competition_display');
    }
}
