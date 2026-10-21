<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021180549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle ADD image_upload_metadata JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD proposed_image_upload_metadata JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD finished_puzzle_photo_upload_metadata JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle DROP image_upload_metadata');
        $this->addSql('ALTER TABLE puzzle_change_request DROP proposed_image_upload_metadata');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP finished_puzzle_photo_upload_metadata');
    }
}
