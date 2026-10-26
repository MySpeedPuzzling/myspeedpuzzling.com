<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026154954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ask to borrow: borrow_request + borrow_request_item, notification target, player.borrow_request_emails';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE borrow_request (note VARCHAR(200) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, send_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, asker_id UUID NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6567891BCF34C66B ON borrow_request (asker_id)');
        $this->addSql('CREATE INDEX IDX_6567891B7E3C61F9 ON borrow_request (owner_id)');
        $this->addSql('CREATE INDEX borrow_request_asker_created ON borrow_request (asker_id, created_at)');
        $this->addSql('CREATE INDEX borrow_request_owner_created ON borrow_request (owner_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX borrow_request_send_owner_unique ON borrow_request (send_id, owner_id)');
        $this->addSql('CREATE TABLE borrow_request_item (status VARCHAR(20) NOT NULL, answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, asker_id UUID NOT NULL, owner_id UUID NOT NULL, request_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_904D4860CF34C66B ON borrow_request_item (asker_id)');
        $this->addSql('CREATE INDEX IDX_904D48607E3C61F9 ON borrow_request_item (owner_id)');
        $this->addSql('CREATE INDEX IDX_904D4860427EB8A5 ON borrow_request_item (request_id)');
        $this->addSql('CREATE INDEX IDX_904D4860D9816812 ON borrow_request_item (puzzle_id)');
        $this->addSql('CREATE INDEX borrow_request_item_owner_puzzle ON borrow_request_item (owner_id, puzzle_id)');
        $this->addSql('CREATE UNIQUE INDEX borrow_request_item_request_puzzle_unique ON borrow_request_item (request_id, puzzle_id)');
        $this->addSql('ALTER TABLE borrow_request ADD CONSTRAINT FK_6567891BCF34C66B FOREIGN KEY (asker_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE borrow_request ADD CONSTRAINT FK_6567891B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE borrow_request_item ADD CONSTRAINT FK_904D4860CF34C66B FOREIGN KEY (asker_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE borrow_request_item ADD CONSTRAINT FK_904D48607E3C61F9 FOREIGN KEY (owner_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE borrow_request_item ADD CONSTRAINT FK_904D4860427EB8A5 FOREIGN KEY (request_id) REFERENCES borrow_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE borrow_request_item ADD CONSTRAINT FK_904D4860D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD target_borrow_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA4F71A703 FOREIGN KEY (target_borrow_request_id) REFERENCES borrow_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA4F71A703 ON notification (target_borrow_request_id)');
        $this->addSql('ALTER TABLE player ADD borrow_request_emails BOOLEAN DEFAULT true NOT NULL');

        // One waiting ask per asker, owner and puzzle - AskToBorrowPuzzlesHandler skips a second one, this keeps a race
        // from creating it anyway
        $this->addSql("CREATE UNIQUE INDEX custom_borrow_item_one_waiting ON borrow_request_item (asker_id, owner_id, puzzle_id) WHERE status = 'waiting'");
        // The asker's open asks of the puzzles on their wishlist page (GetBorrowRequests::openOfAsker())
        $this->addSql("CREATE INDEX custom_borrow_item_asker_open ON borrow_request_item (asker_id, puzzle_id) WHERE status IN ('waiting', 'yes')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE borrow_request DROP CONSTRAINT FK_6567891BCF34C66B');
        $this->addSql('ALTER TABLE borrow_request DROP CONSTRAINT FK_6567891B7E3C61F9');
        $this->addSql('ALTER TABLE borrow_request_item DROP CONSTRAINT FK_904D4860CF34C66B');
        $this->addSql('ALTER TABLE borrow_request_item DROP CONSTRAINT FK_904D48607E3C61F9');
        $this->addSql('ALTER TABLE borrow_request_item DROP CONSTRAINT FK_904D4860427EB8A5');
        $this->addSql('ALTER TABLE borrow_request_item DROP CONSTRAINT FK_904D4860D9816812');
        $this->addSql('DROP TABLE borrow_request');
        $this->addSql('DROP TABLE borrow_request_item');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA4F71A703');
        $this->addSql('DROP INDEX IDX_BF5476CA4F71A703');
        $this->addSql('ALTER TABLE notification DROP target_borrow_request_id');
        $this->addSql('ALTER TABLE player DROP borrow_request_emails');
    }
}
