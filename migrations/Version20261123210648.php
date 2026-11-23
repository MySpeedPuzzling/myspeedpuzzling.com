<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123210648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle: "no barcode on the box" confirmed (ean_absent_confirmed_at); change requests propose it (proposed/original_ean_absent)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle ADD ean_absent_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD proposed_ean_absent BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD original_ean_absent BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle DROP ean_absent_confirmed_at');
        $this->addSql('ALTER TABLE puzzle_change_request DROP proposed_ean_absent');
        $this->addSql('ALTER TABLE puzzle_change_request DROP original_ean_absent');
    }
}
