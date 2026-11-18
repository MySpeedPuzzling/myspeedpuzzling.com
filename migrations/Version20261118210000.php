<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Competition toolkit v2 L2: `competition_round_spotlight` - the entry a round's displays put in the spotlight (one row
 * per round while somebody is in it). Additive: a new table, nothing existing changes. Hand-written (no-Docker mode),
 * matching the entity mapping as doctrine:migrations:diff would.
 */
final class Version20261118210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition round spotlight: the entry a round\'s displays spotlight';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE competition_round_spotlight (entry_ref VARCHAR(60) NOT NULL, set_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, round_id UUID NOT NULL, set_by_id UUID DEFAULT NULL, PRIMARY KEY (round_id))');
        $this->addSql('CREATE INDEX IDX_938C45EA3E16DC62 ON competition_round_spotlight (set_by_id)');
        $this->addSql('ALTER TABLE competition_round_spotlight ADD CONSTRAINT FK_938C45EAA6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_round_spotlight ADD CONSTRAINT FK_938C45EA3E16DC62 FOREIGN KEY (set_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_round_spotlight DROP CONSTRAINT FK_938C45EAA6005CA0');
        $this->addSql('ALTER TABLE competition_round_spotlight DROP CONSTRAINT FK_938C45EA3E16DC62');
        $this->addSql('DROP TABLE competition_round_spotlight');
    }
}
