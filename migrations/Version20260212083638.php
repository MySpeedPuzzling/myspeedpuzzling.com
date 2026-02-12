<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212083638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversation_report (resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, admin_note TEXT DEFAULT NULL, id UUID NOT NULL, reason TEXT NOT NULL, status VARCHAR(255) NOT NULL, reported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_by_id UUID DEFAULT NULL, conversation_id UUID NOT NULL, reporter_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F6E3CD446713A32B ON conversation_report (resolved_by_id)');
        $this->addSql('CREATE INDEX IDX_F6E3CD449AC0396 ON conversation_report (conversation_id)');
        $this->addSql('CREATE INDEX IDX_F6E3CD44E1CFE6F5 ON conversation_report (reporter_id)');
        $this->addSql('CREATE INDEX IDX_F6E3CD447B00651C ON conversation_report (status)');
        $this->addSql('CREATE INDEX IDX_F6E3CD4441D374DA ON conversation_report (reported_at)');
        $this->addSql('CREATE TABLE moderation_action (id UUID NOT NULL, action_type VARCHAR(255) NOT NULL, performed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reason TEXT DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, target_player_id UUID NOT NULL, admin_id UUID NOT NULL, report_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B05D8128642B8210 ON moderation_action (admin_id)');
        $this->addSql('CREATE INDEX IDX_B05D81284BD2A4C0 ON moderation_action (report_id)');
        $this->addSql('CREATE INDEX IDX_B05D8128AD5287F3 ON moderation_action (target_player_id)');
        $this->addSql('CREATE INDEX IDX_B05D8128CC77A1DC ON moderation_action (performed_at)');
        $this->addSql('ALTER TABLE conversation_report ADD CONSTRAINT FK_F6E3CD446713A32B FOREIGN KEY (resolved_by_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation_report ADD CONSTRAINT FK_F6E3CD449AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation_report ADD CONSTRAINT FK_F6E3CD44E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D8128AD5287F3 FOREIGN KEY (target_player_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D8128642B8210 FOREIGN KEY (admin_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D81284BD2A4C0 FOREIGN KEY (report_id) REFERENCES conversation_report (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD messaging_muted BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE player ADD messaging_muted_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD marketplace_banned BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation_report DROP CONSTRAINT FK_F6E3CD446713A32B');
        $this->addSql('ALTER TABLE conversation_report DROP CONSTRAINT FK_F6E3CD449AC0396');
        $this->addSql('ALTER TABLE conversation_report DROP CONSTRAINT FK_F6E3CD44E1CFE6F5');
        $this->addSql('ALTER TABLE moderation_action DROP CONSTRAINT FK_B05D8128AD5287F3');
        $this->addSql('ALTER TABLE moderation_action DROP CONSTRAINT FK_B05D8128642B8210');
        $this->addSql('ALTER TABLE moderation_action DROP CONSTRAINT FK_B05D81284BD2A4C0');
        $this->addSql('DROP TABLE conversation_report');
        $this->addSql('DROP TABLE moderation_action');
        $this->addSql('ALTER TABLE player DROP messaging_muted');
        $this->addSql('ALTER TABLE player DROP messaging_muted_until');
        $this->addSql('ALTER TABLE player DROP marketplace_banned');
    }
}
