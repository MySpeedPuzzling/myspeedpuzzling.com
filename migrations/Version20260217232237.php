<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217232237 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD target_conversation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA4BA2E1F5 FOREIGN KEY (target_conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA4BA2E1F5 ON notification (target_conversation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA4BA2E1F5');
        $this->addSql('DROP INDEX IDX_BF5476CA4BA2E1F5');
        $this->addSql('ALTER TABLE notification DROP target_conversation_id');
    }
}
