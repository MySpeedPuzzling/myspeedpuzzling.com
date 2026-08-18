<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818191846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account deletion: pending e-mail confirmations (split token, cascades with the user account)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_deletion_request (id UUID NOT NULL, selector VARCHAR(255) NOT NULL, hashed_verifier VARCHAR(255) NOT NULL, requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_31051CD69692E25D ON account_deletion_request (selector)');
        $this->addSql('CREATE INDEX IDX_31051CD63C0C9956 ON account_deletion_request (user_account_id)');
        $this->addSql('ALTER TABLE account_deletion_request ADD CONSTRAINT FK_31051CD63C0C9956 FOREIGN KEY (user_account_id) REFERENCES user_account (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_deletion_request DROP CONSTRAINT FK_31051CD63C0C9956');
        $this->addSql('DROP TABLE account_deletion_request');
    }
}
