<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117003849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition ADD competitor_pages BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition ADD competitor_pages_live_results BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition ADD time_proposals BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition ADD qr_tag_mode VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition DROP competitor_pages');
        $this->addSql('ALTER TABLE competition DROP competitor_pages_live_results');
        $this->addSql('ALTER TABLE competition DROP time_proposals');
        $this->addSql('ALTER TABLE competition DROP qr_tag_mode');
    }
}
