<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930130108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prediction history: the prediction stored on every solo solving time';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time ADD predictable BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD prediction_method VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD predicted_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD predicted_range_low_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD predicted_range_high_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD predicted_attempt_number SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD prediction_last_time_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD prediction_source VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD prediction_computed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD prediction_model_version SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_solving_time DROP predictable');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP prediction_method');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP predicted_seconds');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP predicted_range_low_seconds');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP predicted_range_high_seconds');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP predicted_attempt_number');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP prediction_last_time_seconds');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP prediction_source');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP prediction_computed_at');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP prediction_model_version');
    }
}
