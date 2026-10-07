<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007215521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participants spreadsheet: expected team size per team round, change set receipts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE participant_sheet_change_receipt (id UUID NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, outcomes JSON NOT NULL, version_before VARCHAR(64) NOT NULL, version_after VARCHAR(64) NOT NULL, competition_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4FA46CC27B39D312 ON participant_sheet_change_receipt (competition_id)');
        $this->addSql('CREATE INDEX IDX_4FA46CC26D4F7F99 ON participant_sheet_change_receipt (received_at)');
        $this->addSql('ALTER TABLE participant_sheet_change_receipt ADD CONSTRAINT FK_4FA46CC27B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_round ADD team_size SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE participant_sheet_change_receipt DROP CONSTRAINT FK_4FA46CC27B39D312');
        $this->addSql('DROP TABLE participant_sheet_change_receipt');
        $this->addSql('ALTER TABLE competition_round DROP team_size');
    }
}
