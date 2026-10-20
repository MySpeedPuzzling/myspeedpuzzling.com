<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020121230 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Moderator invitations: an admin invites, the player accepts after reading the moderator guide';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE moderator_invitation (answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, accepted BOOLEAN DEFAULT NULL, agreed_guide_version VARCHAR(20) DEFAULT NULL, withdrawn_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, invited_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, invited_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9D58C10299E6F5DF ON moderator_invitation (player_id)');
        $this->addSql('CREATE INDEX IDX_9D58C102A7B4A7E3 ON moderator_invitation (invited_by_id)');
        $this->addSql('ALTER TABLE moderator_invitation ADD CONSTRAINT FK_9D58C10299E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moderator_invitation ADD CONSTRAINT FK_9D58C102A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderator_invitation DROP CONSTRAINT FK_9D58C10299E6F5DF');
        $this->addSql('ALTER TABLE moderator_invitation DROP CONSTRAINT FK_9D58C102A7B4A7E3');
        $this->addSql('DROP TABLE moderator_invitation');
    }
}
