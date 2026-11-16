<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116200911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE results_import_log (id UUID NOT NULL, check_id UUID NOT NULL, imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, events JSONB NOT NULL, teams_added INT NOT NULL, teams_updated INT NOT NULL, teams_removed INT NOT NULL, source_id UUID NOT NULL, imported_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_73B63A0B953C1C61DFD377A1 ON results_import_log (source_id, imported_at)');
        $this->addSql('CREATE INDEX IDX_73B63A0B953C1C61 ON results_import_log (source_id)');
        $this->addSql('CREATE INDEX IDX_73B63A0B74953CEA ON results_import_log (imported_by_id)');
        $this->addSql('ALTER TABLE results_import_log ADD CONSTRAINT FK_73B63A0B953C1C61 FOREIGN KEY (source_id) REFERENCES event_results_sheet (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE results_import_log ADD CONSTRAINT FK_73B63A0B74953CEA FOREIGN KEY (imported_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE results_import_log DROP CONSTRAINT FK_73B63A0B953C1C61');
        $this->addSql('ALTER TABLE results_import_log DROP CONSTRAINT FK_73B63A0B74953CEA');
        $this->addSql('DROP TABLE results_import_log');
    }
}
