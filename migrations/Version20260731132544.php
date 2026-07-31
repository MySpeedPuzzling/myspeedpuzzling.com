<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731132544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Social login (auth hardening PR 2): oauth_identity table per decision D13';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_identity (id UUID NOT NULL, provider VARCHAR(255) NOT NULL, provider_user_id VARCHAR(255) NOT NULL, email_at_link VARCHAR(320) DEFAULT NULL, linked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4AE96AD83C0C9956 ON oauth_identity (user_account_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4AE96AD892C4739C57367132 ON oauth_identity (provider, provider_user_id)');
        $this->addSql('ALTER TABLE oauth_identity ADD CONSTRAINT FK_4AE96AD83C0C9956 FOREIGN KEY (user_account_id) REFERENCES user_account (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_identity DROP CONSTRAINT FK_4AE96AD83C0C9956');
        $this->addSql('DROP TABLE oauth_identity');
    }
}
