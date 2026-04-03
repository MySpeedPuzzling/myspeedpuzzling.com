<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260403124723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition ADD rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD rejection_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD rejected_by_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD CONSTRAINT FK_B50A2CB1BC7FE91 FOREIGN KEY (rejected_by_player_id) REFERENCES player (id)');
        $this->addSql('CREATE INDEX IDX_B50A2CB1BC7FE91 ON competition (rejected_by_player_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition DROP CONSTRAINT FK_B50A2CB1BC7FE91');
        $this->addSql('DROP INDEX IDX_B50A2CB1BC7FE91');
        $this->addSql('ALTER TABLE competition DROP rejected_at');
        $this->addSql('ALTER TABLE competition DROP rejection_reason');
        $this->addSql('ALTER TABLE competition DROP rejected_by_player_id');
    }
}
