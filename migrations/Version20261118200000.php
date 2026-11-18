<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Competition toolkit v2 L1: `competition_display_control` - the live message of an event's displays (one row per
 * event, created by its first message). Additive: a new table, nothing existing changes. Hand-written (no-Docker mode),
 * matching the entity mapping as doctrine:migrations:diff would.
 */
final class Version20261118200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition display control: the live message of an event\'s displays';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE competition_display_control (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message VARCHAR(200) DEFAULT NULL, message_set_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, recent_messages JSONB NOT NULL, competition_id UUID NOT NULL, updated_by_id UUID DEFAULT NULL, PRIMARY KEY (competition_id))');
        $this->addSql('CREATE INDEX IDX_1592670896DBBDE ON competition_display_control (updated_by_id)');
        $this->addSql('ALTER TABLE competition_display_control ADD CONSTRAINT FK_15926707B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_display_control ADD CONSTRAINT FK_1592670896DBBDE FOREIGN KEY (updated_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_display_control DROP CONSTRAINT FK_15926707B39D312');
        $this->addSql('ALTER TABLE competition_display_control DROP CONSTRAINT FK_1592670896DBBDE');
        $this->addSql('DROP TABLE competition_display_control');
    }
}
