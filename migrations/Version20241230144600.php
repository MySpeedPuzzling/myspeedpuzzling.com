<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241230144600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE membership (ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, card_last4_digits VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, stripe_plan_id VARCHAR(255) DEFAULT NULL, billing_period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_86FFD28599E6F5DF ON membership (player_id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD28599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player ADD stripe_customer_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD28599E6F5DF');
        $this->addSql('DROP TABLE membership');
        $this->addSql('ALTER TABLE player DROP stripe_customer_id');
    }
}
