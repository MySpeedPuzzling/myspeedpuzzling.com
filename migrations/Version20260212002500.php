<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212002500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE chat_message (id UUID NOT NULL, content TEXT NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, conversation_id UUID NOT NULL, sender_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FAB3FC169AC0396 ON chat_message (conversation_id)');
        $this->addSql('CREATE INDEX IDX_FAB3FC16F624B39D ON chat_message (sender_id)');
        $this->addSql('CREATE INDEX IDX_FAB3FC169AC039696E4F388 ON chat_message (conversation_id, sent_at)');
        $this->addSql('CREATE TABLE conversation (id UUID NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_message_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, initiator_id UUID NOT NULL, recipient_id UUID NOT NULL, sell_swap_list_item_id UUID DEFAULT NULL, puzzle_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8A8E26E91E4F9FF9 ON conversation (sell_swap_list_item_id)');
        $this->addSql('CREATE INDEX IDX_8A8E26E9D9816812 ON conversation (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_8A8E26E97DB3B714 ON conversation (initiator_id)');
        $this->addSql('CREATE INDEX IDX_8A8E26E9E92F8F78 ON conversation (recipient_id)');
        $this->addSql('CREATE INDEX IDX_8A8E26E97B00651C ON conversation (status)');
        $this->addSql('CREATE INDEX IDX_8A8E26E96F60E3AF ON conversation (last_message_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8A8E26E97DB3B714E92F8F781E4F9FF9 ON conversation (initiator_id, recipient_id, sell_swap_list_item_id)');
        $this->addSql('CREATE TABLE user_block (id UUID NOT NULL, blocked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, blocker_id UUID NOT NULL, blocked_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_61D96C7A21FF5136 ON user_block (blocked_id)');
        $this->addSql('CREATE INDEX IDX_61D96C7A548D5975 ON user_block (blocker_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_61D96C7A548D597521FF5136 ON user_block (blocker_id, blocked_id)');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC169AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC16F624B39D FOREIGN KEY (sender_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E97DB3B714 FOREIGN KEY (initiator_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E9E92F8F78 FOREIGN KEY (recipient_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E91E4F9FF9 FOREIGN KEY (sell_swap_list_item_id) REFERENCES sell_swap_list_item (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E9D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A548D5975 FOREIGN KEY (blocker_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A21FF5136 FOREIGN KEY (blocked_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD allow_direct_messages BOOLEAN DEFAULT true NOT NULL');

        // Custom partial index for efficient unread message queries
        $this->addSql('CREATE INDEX custom_chat_message_unread ON chat_message (conversation_id, sender_id) WHERE read_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_message DROP CONSTRAINT FK_FAB3FC169AC0396');
        $this->addSql('ALTER TABLE chat_message DROP CONSTRAINT FK_FAB3FC16F624B39D');
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E97DB3B714');
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E9E92F8F78');
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E91E4F9FF9');
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E9D9816812');
        $this->addSql('ALTER TABLE user_block DROP CONSTRAINT FK_61D96C7A548D5975');
        $this->addSql('ALTER TABLE user_block DROP CONSTRAINT FK_61D96C7A21FF5136');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE user_block');
        $this->addSql('ALTER TABLE player DROP allow_direct_messages');
    }
}
