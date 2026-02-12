<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212085645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message_notification_log (id UUID NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, oldest_unread_message_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5A77538599E6F5DF ON message_notification_log (player_id)');
        $this->addSql('ALTER TABLE message_notification_log ADD CONSTRAINT FK_5A77538599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD email_notifications_enabled BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message_notification_log DROP CONSTRAINT FK_5A77538599E6F5DF');
        $this->addSql('DROP TABLE message_notification_log');
        $this->addSql('ALTER TABLE player DROP email_notifications_enabled');
    }
}
