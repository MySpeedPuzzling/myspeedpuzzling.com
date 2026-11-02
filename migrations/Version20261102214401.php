<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102214401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE membership ADD stripe_price_lookup_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD scheduled_price_lookup_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD scheduled_price_change_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE membership DROP stripe_price_lookup_key');
        $this->addSql('ALTER TABLE membership DROP scheduled_price_lookup_key');
        $this->addSql('ALTER TABLE membership DROP scheduled_price_change_at');
    }
}
