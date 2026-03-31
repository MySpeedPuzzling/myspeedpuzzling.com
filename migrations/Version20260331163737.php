<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260331163737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make LentPuzzleTransfer self-contained for lend/borrow history auditability';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP CONSTRAINT fk_cab390b09c403190');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD owner_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD puzzle_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD owner_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ALTER lent_puzzle_id DROP NOT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD CONSTRAINT FK_CAB390B09C403190 FOREIGN KEY (lent_puzzle_id) REFERENCES lent_puzzle (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD CONSTRAINT FK_CAB390B0D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD CONSTRAINT FK_CAB390B09B9D866C FOREIGN KEY (owner_player_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CAB390B0D9816812 ON lent_puzzle_transfer (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_CAB390B09B9D866C ON lent_puzzle_transfer (owner_player_id)');

        // Backfill new columns from existing lent_puzzle records (only for active lendings)
        $this->addSql('UPDATE lent_puzzle_transfer lpt SET puzzle_id = lp.puzzle_id, owner_player_id = lp.owner_player_id, owner_name = lp.owner_name FROM lent_puzzle lp WHERE lpt.lent_puzzle_id = lp.id AND lpt.puzzle_id IS NULL');

        $this->addSql('ALTER TABLE notification DROP CONSTRAINT fk_bf5476ca8a2e7ed');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAD44A07A5 FOREIGN KEY (target_transfer_id) REFERENCES lent_puzzle_transfer (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP CONSTRAINT FK_CAB390B09C403190');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP CONSTRAINT FK_CAB390B0D9816812');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP CONSTRAINT FK_CAB390B09B9D866C');
        $this->addSql('DROP INDEX IDX_CAB390B0D9816812');
        $this->addSql('DROP INDEX IDX_CAB390B09B9D866C');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP owner_name');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP puzzle_id');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP owner_player_id');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ALTER lent_puzzle_id SET NOT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD CONSTRAINT fk_cab390b09c403190 FOREIGN KEY (lent_puzzle_id) REFERENCES lent_puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAD44A07A5');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT fk_bf5476ca8a2e7ed FOREIGN KEY (target_transfer_id) REFERENCES lent_puzzle_transfer (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
