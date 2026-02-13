<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260213163957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create request_notification_log table and rename conversation status denied to ignored';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE request_notification_log (id UUID NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, oldest_pending_request_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6B52DC7F99E6F5DF ON request_notification_log (player_id)');
        $this->addSql('ALTER TABLE request_notification_log ADD CONSTRAINT FK_6B52DC7F99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql("UPDATE conversation SET status = 'ignored' WHERE status = 'denied'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_notification_log DROP CONSTRAINT FK_6B52DC7F99E6F5DF');
        $this->addSql('DROP TABLE request_notification_log');
        $this->addSql("UPDATE conversation SET status = 'denied' WHERE status = 'ignored'");
    }
}
