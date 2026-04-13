<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260413210740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_audit_log (status VARCHAR(255) NOT NULL, message_id VARCHAR(255) DEFAULT NULL, error_message TEXT DEFAULT NULL, smtp_debug_log TEXT DEFAULT NULL, bounce_type VARCHAR(255) DEFAULT NULL, bounced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, bounce_reason TEXT DEFAULT NULL, id UUID NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, recipient_email VARCHAR(320) NOT NULL, subject TEXT NOT NULL, transport_name VARCHAR(50) NOT NULL, email_type VARCHAR(100) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F9EA5FDEB3BFA7CF ON email_audit_log (recipient_email)');
        $this->addSql('CREATE INDEX IDX_F9EA5FDE96E4F388 ON email_audit_log (sent_at)');
        $this->addSql('CREATE INDEX IDX_F9EA5FDE7B00651C ON email_audit_log (status)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE email_audit_log');
    }
}
