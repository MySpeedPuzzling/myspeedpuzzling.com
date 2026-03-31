<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260331070823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE feature_request ADD status VARCHAR(255) DEFAULT \'open\' NOT NULL');
        $this->addSql('ALTER TABLE feature_request ADD github_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE feature_request ADD admin_comment TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE feature_request DROP status');
        $this->addSql('ALTER TABLE feature_request DROP github_url');
        $this->addSql('ALTER TABLE feature_request DROP admin_comment');
    }
}
