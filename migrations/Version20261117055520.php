<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117055520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_advancement_step (id UUID NOT NULL, name VARCHAR(80) DEFAULT NULL, source_round_ids JSONB NOT NULL, target_round_ids JSONB NOT NULL, distribution VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, target_by_source JSONB DEFAULT \'{}\' NOT NULL, qualify_count SMALLINT DEFAULT NULL, qualify_scope VARCHAR(16) DEFAULT \'per_round\' NOT NULL, best_of_each_country SMALLINT DEFAULT NULL, position SMALLINT DEFAULT 0 NOT NULL, competition_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3D2D0B897B39D312 ON competition_advancement_step (competition_id)');
        $this->addSql('ALTER TABLE competition_advancement_step ADD CONSTRAINT FK_3D2D0B897B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_advancement_step DROP CONSTRAINT FK_3D2D0B897B39D312');
        $this->addSql('DROP TABLE competition_advancement_step');
    }
}
