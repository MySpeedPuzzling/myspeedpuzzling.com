<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260408191445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create referral program tables (referral, affiliate_payout) and add referral fields to player';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE affiliate_payout (status VARCHAR(255) DEFAULT \'pending\' NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, stripe_invoice_id VARCHAR(64) NOT NULL, payment_amount_cents INT NOT NULL, payout_amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, affiliate_player_id UUID NOT NULL, referral_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1FE99F852875775 ON affiliate_payout (stripe_invoice_id)');
        $this->addSql('CREATE INDEX IDX_C1FE99F87820E771 ON affiliate_payout (affiliate_player_id)');
        $this->addSql('CREATE INDEX IDX_C1FE99F83CCAA4B7 ON affiliate_payout (referral_id)');
        $this->addSql('CREATE TABLE referral (source VARCHAR(255) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, affiliate_player_id UUID NOT NULL, subscriber_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_73079D007820E771 ON referral (affiliate_player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_73079D007808B1AD ON referral (subscriber_id)');
        $this->addSql('ALTER TABLE affiliate_payout ADD CONSTRAINT FK_C1FE99F87820E771 FOREIGN KEY (affiliate_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE affiliate_payout ADD CONSTRAINT FK_C1FE99F83CCAA4B7 FOREIGN KEY (referral_id) REFERENCES referral (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE referral ADD CONSTRAINT FK_73079D007820E771 FOREIGN KEY (affiliate_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE referral ADD CONSTRAINT FK_73079D007808B1AD FOREIGN KEY (subscriber_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD referral_program_joined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD referral_program_suspended BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE affiliate_payout DROP CONSTRAINT FK_C1FE99F87820E771');
        $this->addSql('ALTER TABLE affiliate_payout DROP CONSTRAINT FK_C1FE99F83CCAA4B7');
        $this->addSql('ALTER TABLE referral DROP CONSTRAINT FK_73079D007820E771');
        $this->addSql('ALTER TABLE referral DROP CONSTRAINT FK_73079D007808B1AD');
        $this->addSql('DROP TABLE affiliate_payout');
        $this->addSql('DROP TABLE referral');
        $this->addSql('ALTER TABLE player DROP referral_program_joined_at');
        $this->addSql('ALTER TABLE player DROP referral_program_suspended');
    }
}
