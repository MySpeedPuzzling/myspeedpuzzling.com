<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022220315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification ADD target_partner_listing_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAFF157AE FOREIGN KEY (target_partner_listing_id) REFERENCES partner_listing (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CAFF157AE ON notification (target_partner_listing_id)');
        $this->addSql('ALTER TABLE partner_listing ADD reminded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAFF157AE');
        $this->addSql('DROP INDEX IDX_BF5476CAFF157AE');
        $this->addSql('ALTER TABLE notification DROP target_partner_listing_id');
        $this->addSql('ALTER TABLE partner_listing DROP reminded_at');
    }
}
