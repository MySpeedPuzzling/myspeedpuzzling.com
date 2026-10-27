<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The pair/team prediction model's own tables (docs/features/puzzle-intelligence/README.md "Pair and team
 * predictions"): its fit and what each time's stored pair/team rows were made for. Two new tables, nothing of an
 * existing one changes (the foreign key's check on an empty table is instant).
 */
final class Version20261027083445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pair/team prediction model: group_prediction_fit, group_prediction_input';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE group_prediction_fit (model_version SMALLINT NOT NULL, fitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, parameters JSONB NOT NULL, PRIMARY KEY (model_version))');
        $this->addSql('CREATE TABLE group_prediction_input (fingerprint VARCHAR(32) NOT NULL, live BOOLEAN NOT NULL, written_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, time_id UUID NOT NULL, PRIMARY KEY (time_id))');
        $this->addSql('ALTER TABLE group_prediction_input ADD CONSTRAINT FK_E6BD75EA5EEADD3B FOREIGN KEY (time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_prediction_input DROP CONSTRAINT FK_E6BD75EA5EEADD3B');
        $this->addSql('DROP TABLE group_prediction_fit');
        $this->addSql('DROP TABLE group_prediction_input');
    }
}
