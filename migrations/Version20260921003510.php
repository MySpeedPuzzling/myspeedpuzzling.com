<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260921003510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pairs & teams: puzzling_team_archive - a member keeps a pair/team out of their own shortcuts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzling_team_archive (id UUID NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, team_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_68871315296CD8AE ON puzzling_team_archive (team_id)');
        $this->addSql('CREATE INDEX IDX_6887131599E6F5DF ON puzzling_team_archive (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_68871315296CD8AE99E6F5DF ON puzzling_team_archive (team_id, player_id)');
        $this->addSql('ALTER TABLE puzzling_team_archive ADD CONSTRAINT FK_68871315296CD8AE FOREIGN KEY (team_id) REFERENCES puzzling_team (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzling_team_archive ADD CONSTRAINT FK_6887131599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzling_team_archive DROP CONSTRAINT FK_68871315296CD8AE');
        $this->addSql('ALTER TABLE puzzling_team_archive DROP CONSTRAINT FK_6887131599E6F5DF');
        $this->addSql('DROP TABLE puzzling_team_archive');
    }
}
