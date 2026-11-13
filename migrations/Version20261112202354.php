<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112202354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Claims of imported event results: the append-only claim log, players blocked from claiming (docs/features/event-results-import)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE result_claim_log (id UUID NOT NULL, solving_time_id UUID NOT NULL, slot_key VARCHAR(40) NOT NULL, player_id UUID NOT NULL, player_name VARCHAR(255) NOT NULL, action VARCHAR(255) NOT NULL, by_player_id UUID DEFAULT NULL, reason TEXT DEFAULT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BD8FAEFAD6B05C60 ON result_claim_log (solving_time_id)');
        $this->addSql('CREATE INDEX IDX_BD8FAEFA99E6F5DF47CC8C92 ON result_claim_log (player_id, action)');
        $this->addSql('ALTER TABLE player ADD event_claims_blocked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE result_claim_log');
        $this->addSql('ALTER TABLE player DROP event_claims_blocked_at');
    }
}
