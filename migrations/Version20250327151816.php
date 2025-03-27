<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250327151816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition ADD logo VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD description VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD link VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_link VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD results_link VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD location_country_code VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE competition ADD date_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition RENAME COLUMN date TO date_from');
        $this->addSql('ALTER TABLE competition ALTER date_from TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition DROP logo');
        $this->addSql('ALTER TABLE competition DROP description');
        $this->addSql('ALTER TABLE competition DROP link');
        $this->addSql('ALTER TABLE competition DROP registration_link');
        $this->addSql('ALTER TABLE competition DROP results_link');
        $this->addSql('ALTER TABLE competition DROP location_country_code');
        $this->addSql('ALTER TABLE competition DROP date_to');
        $this->addSql('ALTER TABLE competition RENAME COLUMN date_from TO date');
        $this->addSql('ALTER TABLE competition ALTER date TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
    }
}
