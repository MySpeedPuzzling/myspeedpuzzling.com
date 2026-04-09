<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260409164701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_team (id UUID NOT NULL, name VARCHAR(255) DEFAULT NULL, round_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CAA3380DA6005CA0 ON competition_team (round_id)');
        $this->addSql('ALTER TABLE competition_team ADD CONSTRAINT FK_CAA3380DA6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_participant_round ADD team_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD CONSTRAINT FK_48D4E43296CD8AE FOREIGN KEY (team_id) REFERENCES competition_team (id)');
        $this->addSql('CREATE INDEX IDX_48D4E43296CD8AE ON competition_participant_round (team_id)');
        $this->addSql('ALTER TABLE competition_round ADD category VARCHAR(255) DEFAULT \'solo\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_team DROP CONSTRAINT FK_CAA3380DA6005CA0');
        $this->addSql('DROP TABLE competition_team');
        $this->addSql('ALTER TABLE competition_participant_round DROP CONSTRAINT FK_48D4E43296CD8AE');
        $this->addSql('DROP INDEX IDX_48D4E43296CD8AE');
        $this->addSql('ALTER TABLE competition_participant_round DROP team_id');
        $this->addSql('ALTER TABLE competition_round DROP category');
    }
}
