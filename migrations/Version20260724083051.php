<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724083051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Native auth (issue #147): reset_password_request table (own split-token implementation)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reset_password_request (id UUID NOT NULL, selector VARCHAR(255) NOT NULL, hashed_verifier VARCHAR(255) NOT NULL, requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7CE748A9692E25D ON reset_password_request (selector)');
        $this->addSql('CREATE INDEX IDX_7CE748A3C0C9956 ON reset_password_request (user_account_id)');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748A3C0C9956 FOREIGN KEY (user_account_id) REFERENCES user_account (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reset_password_request DROP CONSTRAINT FK_7CE748A3C0C9956');
        $this->addSql('DROP TABLE reset_password_request');
    }
}
