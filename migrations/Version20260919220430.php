<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260919220430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Private profile allow list: private_profile_viewer';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE private_profile_viewer (id UUID NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, viewer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_814DD4177E3C61F9 ON private_profile_viewer (owner_id)');
        $this->addSql('CREATE INDEX IDX_814DD4176C59C752 ON private_profile_viewer (viewer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_814DD4177E3C61F96C59C752 ON private_profile_viewer (owner_id, viewer_id)');
        $this->addSql('ALTER TABLE private_profile_viewer ADD CONSTRAINT FK_814DD4177E3C61F9 FOREIGN KEY (owner_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE private_profile_viewer ADD CONSTRAINT FK_814DD4176C59C752 FOREIGN KEY (viewer_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE private_profile_viewer DROP CONSTRAINT FK_814DD4177E3C61F9');
        $this->addSql('ALTER TABLE private_profile_viewer DROP CONSTRAINT FK_814DD4176C59C752');
        $this->addSql('DROP TABLE private_profile_viewer');
    }
}
