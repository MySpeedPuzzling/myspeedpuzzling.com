<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117051207 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition toolkit A6: competition_standing (sum standings across rounds)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_standing (id UUID NOT NULL, category VARCHAR(16) NOT NULL, name VARCHAR(80) NOT NULL, slug VARCHAR(100) NOT NULL, round_ids JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, mode VARCHAR(16) DEFAULT \'sum\' NOT NULL, unfinished_penalty_seconds_per_piece SMALLINT DEFAULT 0 NOT NULL, order_rule VARCHAR(32) DEFAULT \'finished_rounds_first\' NOT NULL, missing_rule VARCHAR(32) DEFAULT \'incomplete\' NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, competition_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX competition_standing_slug_unique ON competition_standing (competition_id, slug)');
        $this->addSql('ALTER TABLE competition_standing ADD CONSTRAINT FK_50EBB1F77B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_standing DROP CONSTRAINT FK_50EBB1F77B39D312');
        $this->addSql('DROP TABLE competition_standing');
    }
}
