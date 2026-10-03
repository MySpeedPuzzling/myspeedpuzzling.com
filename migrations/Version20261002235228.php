<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002235228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE comparison_subject (id UUID NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, subject_player_id UUID DEFAULT NULL, subject_team_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AECE6B8899E6F5DF ON comparison_subject (player_id)');
        $this->addSql('CREATE INDEX IDX_AECE6B88DAB761F5 ON comparison_subject (subject_player_id)');
        $this->addSql('CREATE INDEX IDX_AECE6B888CEE740C ON comparison_subject (subject_team_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AECE6B8899E6F5DFDAB761F5 ON comparison_subject (player_id, subject_player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AECE6B8899E6F5DF8CEE740C ON comparison_subject (player_id, subject_team_id)');
        $this->addSql('ALTER TABLE comparison_subject ADD CONSTRAINT FK_AECE6B8899E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comparison_subject ADD CONSTRAINT FK_AECE6B88DAB761F5 FOREIGN KEY (subject_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comparison_subject ADD CONSTRAINT FK_AECE6B888CEE740C FOREIGN KEY (subject_team_id) REFERENCES puzzling_team (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD comparison_view VARCHAR(255) DEFAULT \'cards\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comparison_subject DROP CONSTRAINT FK_AECE6B8899E6F5DF');
        $this->addSql('ALTER TABLE comparison_subject DROP CONSTRAINT FK_AECE6B88DAB761F5');
        $this->addSql('ALTER TABLE comparison_subject DROP CONSTRAINT FK_AECE6B888CEE740C');
        $this->addSql('DROP TABLE comparison_subject');
        $this->addSql('ALTER TABLE player DROP comparison_view');
    }
}
