<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260408083538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create affiliate, tribute, and affiliate_payout tables for Tribute Program';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE affiliate (status VARCHAR(255) DEFAULT \'pending\' NOT NULL, id UUID NOT NULL, code VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_597AA5CF77153098 ON affiliate (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_597AA5CF99E6F5DF ON affiliate (player_id)');
        $this->addSql('CREATE TABLE affiliate_payout (status VARCHAR(255) DEFAULT \'pending\' NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, stripe_invoice_id VARCHAR(64) NOT NULL, payment_amount_cents INT NOT NULL, payout_amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, affiliate_id UUID NOT NULL, tribute_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1FE99F852875775 ON affiliate_payout (stripe_invoice_id)');
        $this->addSql('CREATE INDEX IDX_C1FE99F89F12C49A ON affiliate_payout (affiliate_id)');
        $this->addSql('CREATE INDEX IDX_C1FE99F8D3B581F5 ON affiliate_payout (tribute_id)');
        $this->addSql('CREATE TABLE tribute (source VARCHAR(255) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, affiliate_id UUID NOT NULL, subscriber_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E21D2EA69F12C49A ON tribute (affiliate_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E21D2EA67808B1AD ON tribute (subscriber_id)');
        $this->addSql('ALTER TABLE affiliate ADD CONSTRAINT FK_597AA5CF99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE affiliate_payout ADD CONSTRAINT FK_C1FE99F89F12C49A FOREIGN KEY (affiliate_id) REFERENCES affiliate (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE affiliate_payout ADD CONSTRAINT FK_C1FE99F8D3B581F5 FOREIGN KEY (tribute_id) REFERENCES tribute (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tribute ADD CONSTRAINT FK_E21D2EA69F12C49A FOREIGN KEY (affiliate_id) REFERENCES affiliate (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tribute ADD CONSTRAINT FK_E21D2EA67808B1AD FOREIGN KEY (subscriber_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX custom_affiliate_code_lower ON affiliate (LOWER(code))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE affiliate DROP CONSTRAINT FK_597AA5CF99E6F5DF');
        $this->addSql('ALTER TABLE affiliate_payout DROP CONSTRAINT FK_C1FE99F89F12C49A');
        $this->addSql('ALTER TABLE affiliate_payout DROP CONSTRAINT FK_C1FE99F8D3B581F5');
        $this->addSql('ALTER TABLE tribute DROP CONSTRAINT FK_E21D2EA69F12C49A');
        $this->addSql('ALTER TABLE tribute DROP CONSTRAINT FK_E21D2EA67808B1AD');
        $this->addSql('DROP TABLE affiliate');
        $this->addSql('DROP TABLE affiliate_payout');
        $this->addSql('DROP TABLE tribute');
    }
}
