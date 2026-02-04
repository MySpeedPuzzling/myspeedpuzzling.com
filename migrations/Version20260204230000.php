<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260204230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add percentage discount vouchers support';
    }

    public function up(Schema $schema): void
    {
        // Add new columns to voucher table
        $this->addSql('ALTER TABLE voucher ADD voucher_type VARCHAR(255) DEFAULT \'free_months\' NOT NULL');
        $this->addSql('ALTER TABLE voucher ADD percentage_discount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE voucher ADD max_uses INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE voucher ADD stripe_coupon_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE voucher ALTER months_value DROP NOT NULL');

        // Add claimed_discount_voucher_id to player table
        $this->addSql('ALTER TABLE player ADD claimed_discount_voucher_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD CONSTRAINT FK_98197A65F851FD86 FOREIGN KEY (claimed_discount_voucher_id) REFERENCES voucher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_98197A65F851FD86 ON player (claimed_discount_voucher_id)');

        // Create voucher_claim table
        $this->addSql('CREATE TABLE voucher_claim (
            id UUID NOT NULL,
            voucher_id UUID NOT NULL,
            player_id UUID NOT NULL,
            claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_voucher_claim_voucher ON voucher_claim (voucher_id)');
        $this->addSql('CREATE INDEX IDX_voucher_claim_player ON voucher_claim (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_voucher_player ON voucher_claim (voucher_id, player_id)');
        $this->addSql('ALTER TABLE voucher_claim ADD CONSTRAINT FK_voucher_claim_voucher FOREIGN KEY (voucher_id) REFERENCES voucher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE voucher_claim ADD CONSTRAINT FK_voucher_claim_player FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Drop voucher_claim table
        $this->addSql('DROP TABLE voucher_claim');

        // Remove claimed_discount_voucher_id from player table
        $this->addSql('ALTER TABLE player DROP CONSTRAINT FK_98197A65F851FD86');
        $this->addSql('DROP INDEX IDX_98197A65F851FD86');
        $this->addSql('ALTER TABLE player DROP claimed_discount_voucher_id');

        // Remove new columns from voucher table
        $this->addSql('ALTER TABLE voucher DROP voucher_type');
        $this->addSql('ALTER TABLE voucher DROP percentage_discount');
        $this->addSql('ALTER TABLE voucher DROP max_uses');
        $this->addSql('ALTER TABLE voucher DROP stripe_coupon_id');
        $this->addSql('ALTER TABLE voucher ALTER months_value SET NOT NULL');
    }
}
