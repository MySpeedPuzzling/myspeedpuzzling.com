<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929214137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE login_link_request ADD code_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE login_link_request ADD code_failed_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE login_link_request ADD code_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE login_link_request DROP code_used_at');
        $this->addSql('ALTER TABLE login_link_request DROP code_failed_attempts');
        $this->addSql('ALTER TABLE login_link_request DROP code_hash');
    }
}
