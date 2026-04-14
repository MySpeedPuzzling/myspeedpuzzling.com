<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414032918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_audit_log ADD mta_queue_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_F9EA5FDE537A1329 ON email_audit_log (message_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_F9EA5FDE537A1329');
        $this->addSql('ALTER TABLE email_audit_log DROP mta_queue_id');
    }
}
