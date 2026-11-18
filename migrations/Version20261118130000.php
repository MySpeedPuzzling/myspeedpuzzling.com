<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participant linking L4b: competition_participant.email / claim_invited_at / claim_invitations_refused_at, participant_link_log.player_id nullable (invitation_sent rows), email_suppression.invitations_complained_at';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant ADD email VARCHAR(254) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD claim_invited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD claim_invitations_refused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE email_suppression ADD invitations_complained_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE participant_link_log ALTER player_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant DROP email');
        $this->addSql('ALTER TABLE competition_participant DROP claim_invited_at');
        $this->addSql('ALTER TABLE competition_participant DROP claim_invitations_refused_at');
        $this->addSql('ALTER TABLE email_suppression DROP invitations_complained_at');
        $this->addSql('ALTER TABLE participant_link_log ALTER player_id SET NOT NULL');
    }
}
