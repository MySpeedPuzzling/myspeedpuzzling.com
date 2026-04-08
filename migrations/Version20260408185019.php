<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408185019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Affiliate entity, move referral program fields to Player, update FKs to reference Player directly';
    }

    public function up(Schema $schema): void
    {
        // Add referral program fields to player
        $this->addSql('ALTER TABLE player ADD referral_program_joined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD referral_program_suspended BOOLEAN DEFAULT false NOT NULL');

        // Migrate data from affiliate to player
        $this->addSql("UPDATE player SET referral_program_joined_at = a.created_at, referral_program_suspended = (a.status = 'suspended') FROM affiliate a WHERE a.player_id = player.id");

        // Update referral: affiliate_id -> affiliate_player_id (pointing to player instead of affiliate)
        // FK constraint kept its original name from the tribute table (migration 2 only renamed indexes, not FK constraints)
        $this->addSql('ALTER TABLE referral DROP CONSTRAINT fk_e21d2ea69f12c49a');
        $this->addSql('DROP INDEX IDX_73079D009F12C49A');
        $this->addSql('UPDATE referral SET affiliate_id = a.player_id FROM affiliate a WHERE a.id = referral.affiliate_id');
        $this->addSql('ALTER TABLE referral RENAME COLUMN affiliate_id TO affiliate_player_id');
        $this->addSql('ALTER TABLE referral ADD CONSTRAINT FK_73079D007820E771 FOREIGN KEY (affiliate_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_73079D007820E771 ON referral (affiliate_player_id)');

        // Update affiliate_payout: affiliate_id -> affiliate_player_id (pointing to player instead of affiliate)
        $this->addSql('ALTER TABLE affiliate_payout DROP CONSTRAINT fk_c1fe99f89f12c49a');
        $this->addSql('DROP INDEX idx_c1fe99f89f12c49a');
        $this->addSql('UPDATE affiliate_payout SET affiliate_id = a.player_id FROM affiliate a WHERE a.id = affiliate_payout.affiliate_id');
        $this->addSql('ALTER TABLE affiliate_payout RENAME COLUMN affiliate_id TO affiliate_player_id');
        $this->addSql('ALTER TABLE affiliate_payout ADD CONSTRAINT FK_C1FE99F87820E771 FOREIGN KEY (affiliate_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_C1FE99F87820E771 ON affiliate_payout (affiliate_player_id)');

        // Drop affiliate table and its custom index
        $this->addSql('DROP INDEX IF EXISTS custom_affiliate_code_lower');
        $this->addSql('ALTER TABLE affiliate DROP CONSTRAINT fk_597aa5cf99e6f5df');
        $this->addSql('DROP TABLE affiliate');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE affiliate (status VARCHAR(255) DEFAULT \'pending\' NOT NULL, id UUID NOT NULL, code VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_597aa5cf77153098 ON affiliate (code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_597aa5cf99e6f5df ON affiliate (player_id)');
        $this->addSql('ALTER TABLE affiliate ADD CONSTRAINT fk_597aa5cf99e6f5df FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX custom_affiliate_code_lower ON affiliate (LOWER(code))');

        $this->addSql('ALTER TABLE affiliate_payout DROP CONSTRAINT FK_C1FE99F87820E771');
        $this->addSql('DROP INDEX IDX_C1FE99F87820E771');
        $this->addSql('ALTER TABLE affiliate_payout RENAME COLUMN affiliate_player_id TO affiliate_id');
        $this->addSql('ALTER TABLE affiliate_payout ADD CONSTRAINT fk_c1fe99f89f12c49a FOREIGN KEY (affiliate_id) REFERENCES affiliate (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_c1fe99f89f12c49a ON affiliate_payout (affiliate_id)');

        $this->addSql('ALTER TABLE player DROP referral_program_joined_at');
        $this->addSql('ALTER TABLE player DROP referral_program_suspended');

        $this->addSql('ALTER TABLE referral DROP CONSTRAINT FK_73079D007820E771');
        $this->addSql('DROP INDEX IDX_73079D007820E771');
        $this->addSql('ALTER TABLE referral RENAME COLUMN affiliate_player_id TO affiliate_id');
        $this->addSql('ALTER TABLE referral ADD CONSTRAINT fk_73079d009f12c49a FOREIGN KEY (affiliate_id) REFERENCES affiliate (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_73079d009f12c49a ON referral (affiliate_id)');
    }
}
