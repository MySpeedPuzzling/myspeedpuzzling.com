<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007232122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE followed_competition (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, competition_id UUID DEFAULT NULL, series_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ED8E421599E6F5DF ON followed_competition (player_id)');
        $this->addSql('CREATE INDEX IDX_ED8E42157B39D312 ON followed_competition (competition_id)');
        $this->addSql('CREATE INDEX IDX_ED8E42155278319C ON followed_competition (series_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ED8E421599E6F5DF7B39D312 ON followed_competition (player_id, competition_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ED8E421599E6F5DF5278319C ON followed_competition (player_id, series_id)');
        $this->addSql('ALTER TABLE followed_competition ADD CONSTRAINT FK_ED8E421599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE followed_competition ADD CONSTRAINT FK_ED8E42157B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE followed_competition ADD CONSTRAINT FK_ED8E42155278319C FOREIGN KEY (series_id) REFERENCES competition_series (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE followed_competition DROP CONSTRAINT FK_ED8E421599E6F5DF');
        $this->addSql('ALTER TABLE followed_competition DROP CONSTRAINT FK_ED8E42157B39D312');
        $this->addSql('ALTER TABLE followed_competition DROP CONSTRAINT FK_ED8E42155278319C');
        $this->addSql('DROP TABLE followed_competition');
    }
}
