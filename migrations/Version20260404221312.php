<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260404221312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_series (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, link VARCHAR(255) DEFAULT NULL, is_online BOOLEAN DEFAULT false NOT NULL, location VARCHAR(255) DEFAULT NULL, location_country_code VARCHAR(255) DEFAULT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, added_by_player_id UUID DEFAULT NULL, approved_by_player_id UUID DEFAULT NULL, rejected_by_player_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5553C1FE989D9B62 ON competition_series (slug)');
        $this->addSql('CREATE INDEX IDX_5553C1FE3EDBBB76 ON competition_series (added_by_player_id)');
        $this->addSql('CREATE INDEX IDX_5553C1FE43132E94 ON competition_series (approved_by_player_id)');
        $this->addSql('CREATE INDEX IDX_5553C1FEBC7FE91 ON competition_series (rejected_by_player_id)');
        $this->addSql('CREATE TABLE competition_series_maintainer (competition_series_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (competition_series_id, player_id))');
        $this->addSql('CREATE INDEX IDX_773B679EF9987DFE ON competition_series_maintainer (competition_series_id)');
        $this->addSql('CREATE INDEX IDX_773B679E99E6F5DF ON competition_series_maintainer (player_id)');
        $this->addSql('ALTER TABLE competition_series ADD CONSTRAINT FK_5553C1FE3EDBBB76 FOREIGN KEY (added_by_player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE competition_series ADD CONSTRAINT FK_5553C1FE43132E94 FOREIGN KEY (approved_by_player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE competition_series ADD CONSTRAINT FK_5553C1FEBC7FE91 FOREIGN KEY (rejected_by_player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE competition_series_maintainer ADD CONSTRAINT FK_773B679EF9987DFE FOREIGN KEY (competition_series_id) REFERENCES competition_series (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE competition_series_maintainer ADD CONSTRAINT FK_773B679E99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE competition ADD series_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD CONSTRAINT FK_B50A2CB15278319C FOREIGN KEY (series_id) REFERENCES competition_series (id)');
        $this->addSql('CREATE INDEX IDX_B50A2CB15278319C ON competition (series_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_series DROP CONSTRAINT FK_5553C1FE3EDBBB76');
        $this->addSql('ALTER TABLE competition_series DROP CONSTRAINT FK_5553C1FE43132E94');
        $this->addSql('ALTER TABLE competition_series DROP CONSTRAINT FK_5553C1FEBC7FE91');
        $this->addSql('ALTER TABLE competition_series_maintainer DROP CONSTRAINT FK_773B679EF9987DFE');
        $this->addSql('ALTER TABLE competition_series_maintainer DROP CONSTRAINT FK_773B679E99E6F5DF');
        $this->addSql('DROP TABLE competition_series');
        $this->addSql('DROP TABLE competition_series_maintainer');
        $this->addSql('ALTER TABLE competition DROP CONSTRAINT FK_B50A2CB15278319C');
        $this->addSql('DROP INDEX IDX_B50A2CB15278319C');
        $this->addSql('ALTER TABLE competition DROP series_id');
    }
}
