<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117162948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_time_proposal ADD timing_verdict VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_time_proposal ADD last_heard_at_ms BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_time_proposal ADD clock_uncertain BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_time_proposal ADD offset_sample_age_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_time_proposal ADD queued_offline BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_time_proposal ADD send_attempts INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_time_proposal DROP timing_verdict');
        $this->addSql('ALTER TABLE competition_time_proposal DROP last_heard_at_ms');
        $this->addSql('ALTER TABLE competition_time_proposal DROP clock_uncertain');
        $this->addSql('ALTER TABLE competition_time_proposal DROP offset_sample_age_ms');
        $this->addSql('ALTER TABLE competition_time_proposal DROP queued_offline');
        $this->addSql('ALTER TABLE competition_time_proposal DROP send_attempts');
    }
}
