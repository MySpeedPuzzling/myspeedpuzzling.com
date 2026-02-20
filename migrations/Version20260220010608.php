<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260220010608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge message_notification_log and request_notification_log into digest_email_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE digest_email_log (id UUID NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, oldest_unread_message_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, oldest_pending_request_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, oldest_unread_notification_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7A8967E099E6F5DF ON digest_email_log (player_id)');
        $this->addSql('ALTER TABLE digest_email_log ADD CONSTRAINT FK_7A8967E099E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE');

        // Migrate data from old tables
        $this->addSql('INSERT INTO digest_email_log (id, player_id, sent_at, oldest_unread_message_at) SELECT id, player_id, sent_at, oldest_unread_message_at FROM message_notification_log');
        $this->addSql('INSERT INTO digest_email_log (id, player_id, sent_at, oldest_pending_request_at) SELECT id, player_id, sent_at, oldest_pending_request_at FROM request_notification_log');

        $this->addSql('ALTER TABLE message_notification_log DROP CONSTRAINT fk_5a77538599e6f5df');
        $this->addSql('ALTER TABLE request_notification_log DROP CONSTRAINT fk_6b52dc7f99e6f5df');
        $this->addSql('DROP TABLE message_notification_log');
        $this->addSql('DROP TABLE request_notification_log');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message_notification_log (id UUID NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, oldest_unread_message_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_5a77538599e6f5df ON message_notification_log (player_id)');
        $this->addSql('CREATE TABLE request_notification_log (id UUID NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, oldest_pending_request_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_6b52dc7f99e6f5df ON request_notification_log (player_id)');
        $this->addSql('ALTER TABLE message_notification_log ADD CONSTRAINT fk_5a77538599e6f5df FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE request_notification_log ADD CONSTRAINT fk_6b52dc7f99e6f5df FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE digest_email_log DROP CONSTRAINT FK_7A8967E099E6F5DF');
        $this->addSql('DROP TABLE digest_email_log');
    }
}
