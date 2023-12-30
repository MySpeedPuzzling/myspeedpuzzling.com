<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231230162841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player ADD avatar VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD facebook VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD instagram VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD bio TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE player DROP avatar');
        $this->addSql('ALTER TABLE player DROP facebook');
        $this->addSql('ALTER TABLE player DROP instagram');
        $this->addSql('ALTER TABLE player DROP bio');
    }
}
