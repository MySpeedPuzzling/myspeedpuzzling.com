<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251204122938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target_transfer_id column to notification table for lending notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD target_transfer_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA8A2E7ED FOREIGN KEY (target_transfer_id) REFERENCES lent_puzzle_transfer (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_BF5476CA8A2E7ED ON notification (target_transfer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA8A2E7ED');
        $this->addSql('DROP INDEX IDX_BF5476CA8A2E7ED');
        $this->addSql('ALTER TABLE notification DROP target_transfer_id');
    }
}
