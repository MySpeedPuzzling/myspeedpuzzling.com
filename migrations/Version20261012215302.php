<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012215302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_suppression (id UUID NOT NULL, email VARCHAR(320) NOT NULL, suppressed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, bounce_sub_type VARCHAR(50) DEFAULT NULL, diagnostic_code TEXT DEFAULT NULL, email_type VARCHAR(100) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BD4BE42EE7927C74 ON email_suppression (email)');
        $this->addSql('ALTER TABLE email_audit_log ADD delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE email_audit_log ADD opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE email_audit_log ADD clicked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE email_audit_log ADD complained_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE email_suppression');
        $this->addSql('ALTER TABLE email_audit_log DROP delivered_at');
        $this->addSql('ALTER TABLE email_audit_log DROP opened_at');
        $this->addSql('ALTER TABLE email_audit_log DROP clicked_at');
        $this->addSql('ALTER TABLE email_audit_log DROP complained_at');
    }
}
