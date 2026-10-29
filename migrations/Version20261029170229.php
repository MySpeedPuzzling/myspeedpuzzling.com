<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029170229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-mail suppression: source (hard bounce, domain not found, blocked by hand) + the reason the player reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_suppression ADD source VARCHAR(30) DEFAULT \'hard_bounce\' NOT NULL');
        $this->addSql('ALTER TABLE email_suppression ADD reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_suppression DROP source');
        $this->addSql('ALTER TABLE email_suppression DROP reason');
    }
}
