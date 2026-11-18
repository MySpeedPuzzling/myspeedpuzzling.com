<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Participant linking L2b (docs/features/competitions-management/participant-linking.md §9.2): the notification's
 * target_participant_link_request_id and target_competition_participant_id. Written by hand the way
 * doctrine:migrations:diff generates it (no-Docker mode) - the foreign key indexes are partial custom ones instead of
 * Doctrine's full IDX_BF5476CA15541F44 / IDX_BF5476CA12667544: NULL on every notification but these
 * (.claude/rules/database.md). Adding a nullable column without a default is metadata only.
 */
final class Version20261118000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participant linking notifications: notification.target_participant_link_request_id, target_competition_participant_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD target_participant_link_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD target_competition_participant_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA15541F44 FOREIGN KEY (target_participant_link_request_id) REFERENCES participant_link_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA12667544 FOREIGN KEY (target_competition_participant_id) REFERENCES competition_participant (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Instead of Doctrine's full IDX_BF5476CA15541F44 / IDX_BF5476CA12667544 (docs/database-indexes.md)
        $this->addSql('CREATE INDEX custom_notification_fk_target_participant_link_request_id ON notification (target_participant_link_request_id) WHERE target_participant_link_request_id IS NOT NULL');
        $this->addSql('CREATE INDEX custom_notification_fk_target_competition_participant_id ON notification (target_competition_participant_id) WHERE target_competition_participant_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA15541F44');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA12667544');
        $this->addSql('DROP INDEX custom_notification_fk_target_participant_link_request_id');
        $this->addSql('DROP INDEX custom_notification_fk_target_competition_participant_id');
        $this->addSql('ALTER TABLE notification DROP target_participant_link_request_id');
        $this->addSql('ALTER TABLE notification DROP target_competition_participant_id');
    }
}
