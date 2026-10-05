<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261004233750 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_change_request ADD proposed_alternative_names JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD proposed_name_language VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD original_alternative_names JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD original_name_language VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_change_request DROP proposed_alternative_names');
        $this->addSql('ALTER TABLE puzzle_change_request DROP proposed_name_language');
        $this->addSql('ALTER TABLE puzzle_change_request DROP original_alternative_names');
        $this->addSql('ALTER TABLE puzzle_change_request DROP original_name_language');
    }
}
