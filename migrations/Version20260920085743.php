<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260920085743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pairs & teams: puzzling_team, puzzling_team_member, puzzle_solving_time.puzzling_team_id, notification.target_puzzling_team_id';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzling_team (named_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, composition_key CHAR(40) NOT NULL, size SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(50) DEFAULT NULL, named_by_id UUID DEFAULT NULL, prepared_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_10E333A712F5C85C ON puzzling_team (composition_key)');
        $this->addSql('CREATE INDEX IDX_10E333A78CC3AE7D ON puzzling_team (named_by_id)');
        $this->addSql('CREATE INDEX IDX_10E333A7393065A9 ON puzzling_team (prepared_by_id)');
        $this->addSql('CREATE TABLE puzzling_team_member (id UUID NOT NULL, member_key VARCHAR(255) NOT NULL, guest_name VARCHAR(255) DEFAULT NULL, position SMALLINT NOT NULL, team_id UUID NOT NULL, player_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B6586F88296CD8AE ON puzzling_team_member (team_id)');
        $this->addSql('CREATE INDEX IDX_B6586F8899E6F5DF ON puzzling_team_member (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B6586F88296CD8AEBB348BF2 ON puzzling_team_member (team_id, member_key)');
        $this->addSql('ALTER TABLE puzzling_team ADD CONSTRAINT FK_10E333A78CC3AE7D FOREIGN KEY (named_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzling_team ADD CONSTRAINT FK_10E333A7393065A9 FOREIGN KEY (prepared_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzling_team_member ADD CONSTRAINT FK_B6586F88296CD8AE FOREIGN KEY (team_id) REFERENCES puzzling_team (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzling_team_member ADD CONSTRAINT FK_B6586F8899E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD target_puzzling_team_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA3E76637 FOREIGN KEY (target_puzzling_team_id) REFERENCES puzzling_team (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CAA3E76637 ON notification (target_puzzling_team_id)');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD puzzling_team_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93C771E5837 FOREIGN KEY (puzzling_team_id) REFERENCES puzzling_team (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_FE83A93C771E5837 ON puzzle_solving_time (puzzling_team_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzling_team DROP CONSTRAINT FK_10E333A78CC3AE7D');
        $this->addSql('ALTER TABLE puzzling_team DROP CONSTRAINT FK_10E333A7393065A9');
        $this->addSql('ALTER TABLE puzzling_team_member DROP CONSTRAINT FK_B6586F88296CD8AE');
        $this->addSql('ALTER TABLE puzzling_team_member DROP CONSTRAINT FK_B6586F8899E6F5DF');
        $this->addSql('DROP TABLE puzzling_team');
        $this->addSql('DROP TABLE puzzling_team_member');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAA3E76637');
        $this->addSql('DROP INDEX IDX_BF5476CAA3E76637');
        $this->addSql('ALTER TABLE notification DROP target_puzzling_team_id');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93C771E5837');
        $this->addSql('DROP INDEX IDX_FE83A93C771E5837');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP puzzling_team_id');
    }
}
