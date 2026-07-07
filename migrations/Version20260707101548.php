<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707101548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition ADD registration_managed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition ADD capacity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_opens_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_closes_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD entry_fee_text VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD payment_instructions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD registration_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD registered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD checked_in_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD organizer_note VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition DROP registration_managed');
        $this->addSql('ALTER TABLE competition DROP capacity');
        $this->addSql('ALTER TABLE competition DROP registration_opens_at');
        $this->addSql('ALTER TABLE competition DROP registration_closes_at');
        $this->addSql('ALTER TABLE competition DROP entry_fee_text');
        $this->addSql('ALTER TABLE competition DROP payment_instructions');
        $this->addSql('ALTER TABLE competition_participant DROP registration_status');
        $this->addSql('ALTER TABLE competition_participant DROP registered_at');
        $this->addSql('ALTER TABLE competition_participant DROP paid_at');
        $this->addSql('ALTER TABLE competition_participant DROP checked_in_at');
        $this->addSql('ALTER TABLE competition_participant DROP organizer_note');
    }
}
