<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216214705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_message ADD system_message_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_message ADD system_message_target_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_message ALTER sender_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_message DROP system_message_type');
        $this->addSql('ALTER TABLE chat_message DROP system_message_target_player_id');
        $this->addSql('ALTER TABLE chat_message ALTER sender_id SET NOT NULL');
    }
}
