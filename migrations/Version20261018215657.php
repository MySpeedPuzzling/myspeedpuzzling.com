<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018215657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights 5b: puzzle_difficulty_estimate (picture-based difficulty, current value per puzzle) + puzzle_difficulty_estimate_model (model runs with their evaluation)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_difficulty_estimate (model VARCHAR(50) NOT NULL, model_version VARCHAR(50) NOT NULL, log_difficulty DOUBLE PRECISION NOT NULL, log_p10 DOUBLE PRECISION NOT NULL, log_p90 DOUBLE PRECISION NOT NULL, out_of_fold BOOLEAN NOT NULL, training_size INT NOT NULL, image_fingerprint VARCHAR(64) NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('CREATE TABLE puzzle_difficulty_estimate_model (id UUID NOT NULL, model VARCHAR(50) NOT NULL, model_version VARCHAR(50) NOT NULL, training_size INT NOT NULL, holdout_spearman DOUBLE PRECISION NOT NULL, evaluation JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_461FD724D79572D96D4F7F99 ON puzzle_difficulty_estimate_model (model, received_at)');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate ADD CONSTRAINT FK_D7F4CF98D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate DROP CONSTRAINT FK_D7F4CF98D9816812');
        $this->addSql('DROP TABLE puzzle_difficulty_estimate');
        $this->addSql('DROP TABLE puzzle_difficulty_estimate_model');
    }
}
