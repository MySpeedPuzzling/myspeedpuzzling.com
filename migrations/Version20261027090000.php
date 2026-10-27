<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights gate v2: image_estimate_evaluation gate_version (old rows 1), subject (image or a candidate time model), split_at; newer_than nullable (candidate records over every puzzle)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE image_estimate_evaluation ADD gate_version SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE image_estimate_evaluation ADD subject VARCHAR(80) DEFAULT \'image\' NOT NULL');
        $this->addSql('ALTER TABLE image_estimate_evaluation ADD split_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE image_estimate_evaluation ALTER newer_than DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE image_estimate_evaluation DROP gate_version');
        $this->addSql('ALTER TABLE image_estimate_evaluation DROP subject');
        $this->addSql('ALTER TABLE image_estimate_evaluation DROP split_at');
        $this->addSql('ALTER TABLE image_estimate_evaluation ALTER newer_than SET NOT NULL');
    }
}
