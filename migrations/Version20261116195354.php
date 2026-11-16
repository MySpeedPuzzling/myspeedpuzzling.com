<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116195354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE results_import_check (id UUID NOT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, snapshot JSONB DEFAULT NULL, read_error TEXT DEFAULT NULL, source_id UUID NOT NULL, checked_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EC929A82953C1C61D77FEDA0 ON results_import_check (source_id, checked_at)');
        $this->addSql('CREATE INDEX IDX_EC929A82953C1C61 ON results_import_check (source_id)');
        $this->addSql('CREATE INDEX IDX_EC929A822199DB86 ON results_import_check (checked_by_id)');
        $this->addSql('ALTER TABLE results_import_check ADD CONSTRAINT FK_EC929A82953C1C61 FOREIGN KEY (source_id) REFERENCES event_results_sheet (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE results_import_check ADD CONSTRAINT FK_EC929A822199DB86 FOREIGN KEY (checked_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE results_import_check DROP CONSTRAINT FK_EC929A82953C1C61');
        $this->addSql('ALTER TABLE results_import_check DROP CONSTRAINT FK_EC929A822199DB86');
        $this->addSql('DROP TABLE results_import_check');
    }
}
