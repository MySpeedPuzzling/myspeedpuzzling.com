<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231121205445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manufacturer ADD added_by_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE manufacturer ADD approved BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE manufacturer ADD added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN manufacturer.added_by_user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN manufacturer.added_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE manufacturer ADD CONSTRAINT FK_3D0AE6DCCA792C6B FOREIGN KEY (added_by_user_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_3D0AE6DCCA792C6B ON manufacturer (added_by_user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE manufacturer DROP CONSTRAINT FK_3D0AE6DCCA792C6B');
        $this->addSql('DROP INDEX IDX_3D0AE6DCCA792C6B');
        $this->addSql('ALTER TABLE manufacturer DROP added_by_user_id');
        $this->addSql('ALTER TABLE manufacturer DROP approved');
        $this->addSql('ALTER TABLE manufacturer DROP added_at');
    }
}
