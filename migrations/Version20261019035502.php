<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019035502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights 5d: image prior - image / blend side-by-side rows (image_model, image_kind), blend prior per puzzle, gate record of the image estimates';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE image_estimate_evaluation (id UUID NOT NULL, evaluated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, model_version SMALLINT NOT NULL, newer_than TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, measured_min_indices SMALLINT NOT NULL, groups JSONB NOT NULL, overall JSONB NOT NULL, by_k JSONB NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8E487B2EA9FBD16C ON image_estimate_evaluation (evaluated_at)');
        $this->addSql('ALTER TABLE difficulty_prior_puzzle ADD blend_prior_log DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_difficulty ADD blend_prior_log DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE solving_time_prediction ADD image_model VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE solving_time_prediction ADD image_kind VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE image_estimate_evaluation');
        $this->addSql('ALTER TABLE difficulty_prior_puzzle DROP blend_prior_log');
        $this->addSql('ALTER TABLE puzzle_difficulty DROP blend_prior_log');
        $this->addSql('ALTER TABLE solving_time_prediction DROP image_model');
        $this->addSql('ALTER TABLE solving_time_prediction DROP image_kind');
    }
}
