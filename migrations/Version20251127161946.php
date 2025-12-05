<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251127161946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lending/borrowing feature tables and player visibility setting';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lent_puzzle (id UUID NOT NULL, lent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, notes TEXT DEFAULT NULL, puzzle_id UUID NOT NULL, owner_player_id UUID NOT NULL, current_holder_player_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FCC55859D9816812 ON lent_puzzle (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_FCC558599B9D866C ON lent_puzzle (owner_player_id)');
        $this->addSql('CREATE INDEX IDX_FCC55859173B2047 ON lent_puzzle (current_holder_player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FCC558599B9D866CD9816812 ON lent_puzzle (owner_player_id, puzzle_id)');
        $this->addSql('CREATE TABLE lent_puzzle_transfer (id UUID NOT NULL, transferred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, transfer_type VARCHAR(255) NOT NULL, lent_puzzle_id UUID NOT NULL, from_player_id UUID DEFAULT NULL, to_player_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CAB390B09C403190 ON lent_puzzle_transfer (lent_puzzle_id)');
        $this->addSql('CREATE INDEX IDX_CAB390B0897A6065 ON lent_puzzle_transfer (from_player_id)');
        $this->addSql('CREATE INDEX IDX_CAB390B0A84522AE ON lent_puzzle_transfer (to_player_id)');
        $this->addSql('ALTER TABLE lent_puzzle ADD CONSTRAINT FK_FCC55859D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE lent_puzzle ADD CONSTRAINT FK_FCC558599B9D866C FOREIGN KEY (owner_player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE lent_puzzle ADD CONSTRAINT FK_FCC55859173B2047 FOREIGN KEY (current_holder_player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD CONSTRAINT FK_CAB390B09C403190 FOREIGN KEY (lent_puzzle_id) REFERENCES lent_puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD CONSTRAINT FK_CAB390B0897A6065 FOREIGN KEY (from_player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD CONSTRAINT FK_CAB390B0A84522AE FOREIGN KEY (to_player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player ADD lend_borrow_list_visibility VARCHAR(255) DEFAULT \'private\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lent_puzzle DROP CONSTRAINT FK_FCC55859D9816812');
        $this->addSql('ALTER TABLE lent_puzzle DROP CONSTRAINT FK_FCC558599B9D866C');
        $this->addSql('ALTER TABLE lent_puzzle DROP CONSTRAINT FK_FCC55859173B2047');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP CONSTRAINT FK_CAB390B09C403190');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP CONSTRAINT FK_CAB390B0897A6065');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP CONSTRAINT FK_CAB390B0A84522AE');
        $this->addSql('DROP TABLE lent_puzzle');
        $this->addSql('DROP TABLE lent_puzzle_transfer');
        $this->addSql('ALTER TABLE player DROP lend_borrow_list_visibility');
    }
}
