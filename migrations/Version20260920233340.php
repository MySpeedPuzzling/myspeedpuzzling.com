<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260920233340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Free trial of membership (membership.trial_*), announcement modal impressions, active_trials in the activity summary';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE player_modal_impression (seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, modal VARCHAR(64) NOT NULL, displayed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6C0A522B99E6F5DF ON player_modal_impression (player_id)');
        $this->addSql('CREATE INDEX IDX_6C0A522BB3F9B7DDB9957460 ON player_modal_impression (modal, displayed_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6C0A522B99E6F5DFB3F9B7DD ON player_modal_impression (player_id, modal)');
        $this->addSql('ALTER TABLE player_modal_impression ADD CONSTRAINT FK_6C0A522B99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_daily_summary ADD active_trials INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE membership ADD trial_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD trial_source VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD trial_ending_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD trial_converted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_modal_impression DROP CONSTRAINT FK_6C0A522B99E6F5DF');
        $this->addSql('DROP TABLE player_modal_impression');
        $this->addSql('ALTER TABLE activity_daily_summary DROP active_trials');
        $this->addSql('ALTER TABLE membership DROP trial_started_at');
        $this->addSql('ALTER TABLE membership DROP trial_ends_at');
        $this->addSql('ALTER TABLE membership DROP trial_source');
        $this->addSql('ALTER TABLE membership DROP trial_ending_reminder_sent_at');
        $this->addSql('ALTER TABLE membership DROP trial_converted_at');
    }
}
