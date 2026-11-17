<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117210730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participant linking L1a: competition_participant.connected_via + participant_link_log';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE participant_link_log (id UUID NOT NULL, competition_id UUID NOT NULL, participant_id UUID NOT NULL, participant_name VARCHAR(255) NOT NULL, player_id UUID NOT NULL, player_name VARCHAR(255) NOT NULL, action VARCHAR(30) NOT NULL, method VARCHAR(20) DEFAULT NULL, by_player_id UUID DEFAULT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CD0560037B39D3129D1C3019 ON participant_link_log (competition_id, participant_id)');
        $this->addSql('CREATE INDEX IDX_CD05600399E6F5DF47CC8C92 ON participant_link_log (player_id, action)');
        $this->addSql('ALTER TABLE competition_participant ADD connected_via VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE participant_link_log');
        $this->addSql('ALTER TABLE competition_participant DROP connected_via');
    }
}
