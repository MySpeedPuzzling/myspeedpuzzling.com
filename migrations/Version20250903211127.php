<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250903211127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create puzzle collection tables and update notification entity';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_borrowing (returned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, non_registered_person_name VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, borrowed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, borrowed_from BOOLEAN NOT NULL, puzzle_id UUID DEFAULT NULL, owner_id UUID DEFAULT NULL, borrower_id UUID DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3E566518D9816812 ON puzzle_borrowing (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_3E5665187E3C61F9 ON puzzle_borrowing (owner_id)');
        $this->addSql('CREATE INDEX IDX_3E56651811CE312B ON puzzle_borrowing (borrower_id)');
        $this->addSql('CREATE TABLE puzzle_collection (description TEXT DEFAULT NULL, is_public BOOLEAN NOT NULL, system_type VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8156C3BE99E6F5DF ON puzzle_collection (player_id)');
        $this->addSql('CREATE TABLE puzzle_collection_item (comment TEXT DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, condition VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, collection_id UUID DEFAULT NULL, puzzle_id UUID DEFAULT NULL, player_id UUID DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C3985990514956FD ON puzzle_collection_item (collection_id)');
        $this->addSql('CREATE INDEX IDX_C3985990D9816812 ON puzzle_collection_item (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_C398599099E6F5DF ON puzzle_collection_item (player_id)');
        $this->addSql('ALTER TABLE puzzle_borrowing ADD CONSTRAINT FK_3E566518D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_borrowing ADD CONSTRAINT FK_3E5665187E3C61F9 FOREIGN KEY (owner_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_borrowing ADD CONSTRAINT FK_3E56651811CE312B FOREIGN KEY (borrower_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_collection ADD CONSTRAINT FK_8156C3BE99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_collection_item ADD CONSTRAINT FK_C3985990514956FD FOREIGN KEY (collection_id) REFERENCES puzzle_collection (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_collection_item ADD CONSTRAINT FK_C3985990D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_collection_item ADD CONSTRAINT FK_C398599099E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE notification ADD target_puzzle_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD other_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAED351A3E FOREIGN KEY (target_puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA47197C6A FOREIGN KEY (other_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_BF5476CAED351A3E ON notification (target_puzzle_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA47197C6A ON notification (other_player_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_player_puzzle_custom_collection ON puzzle_collection_item (player_id, puzzle_id) WHERE collection_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_player_collection_system_type ON puzzle_collection (player_id, system_type) WHERE system_type IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX unique_player_puzzle_custom_collection');
        $this->addSql('DROP INDEX unique_player_collection_system_type');
        $this->addSql('ALTER TABLE puzzle_borrowing DROP CONSTRAINT FK_3E566518D9816812');
        $this->addSql('ALTER TABLE puzzle_borrowing DROP CONSTRAINT FK_3E5665187E3C61F9');
        $this->addSql('ALTER TABLE puzzle_borrowing DROP CONSTRAINT FK_3E56651811CE312B');
        $this->addSql('ALTER TABLE puzzle_collection DROP CONSTRAINT FK_8156C3BE99E6F5DF');
        $this->addSql('ALTER TABLE puzzle_collection_item DROP CONSTRAINT FK_C3985990514956FD');
        $this->addSql('ALTER TABLE puzzle_collection_item DROP CONSTRAINT FK_C3985990D9816812');
        $this->addSql('ALTER TABLE puzzle_collection_item DROP CONSTRAINT FK_C398599099E6F5DF');
        $this->addSql('DROP TABLE puzzle_borrowing');
        $this->addSql('DROP TABLE puzzle_collection');
        $this->addSql('DROP TABLE puzzle_collection_item');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAED351A3E');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA47197C6A');
        $this->addSql('DROP INDEX IDX_BF5476CAED351A3E');
        $this->addSql('DROP INDEX IDX_BF5476CA47197C6A');
        $this->addSql('ALTER TABLE notification DROP target_puzzle_id');
        $this->addSql('ALTER TABLE notification DROP other_player_id');
    }
}
