<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731113901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE auth_audit_log (id UUID NOT NULL, email VARCHAR(320) DEFAULT NULL, event_type VARCHAR(255) NOT NULL, authenticator VARCHAR(50) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, metadata JSONB DEFAULT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_account_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F21E1C673C0C9956 ON auth_audit_log (user_account_id)');
        $this->addSql('CREATE INDEX IDX_F21E1C673C0C995687C03D1B ON auth_audit_log (user_account_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_F21E1C6787C03D1B ON auth_audit_log (occurred_at)');
        $this->addSql('CREATE INDEX IDX_F21E1C6793151B8287C03D1B ON auth_audit_log (event_type, occurred_at)');
        $this->addSql('ALTER TABLE auth_audit_log ADD CONSTRAINT FK_F21E1C673C0C9956 FOREIGN KEY (user_account_id) REFERENCES user_account (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE auth_audit_log DROP CONSTRAINT FK_F21E1C673C0C9956');
        $this->addSql('DROP TABLE auth_audit_log');
    }
}
