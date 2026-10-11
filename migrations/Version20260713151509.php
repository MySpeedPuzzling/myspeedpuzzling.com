<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713151509 extends AbstractMigration
{
    public function getDescription(): string
    {
        // Rewritten 2026-10-10 (activity digest: frequency weekly / every two weeks / monthly / off, one e-mail per player
        // and period, run table for pacing + circuit breaker) - never deployed before, so changed in place. A local
        // database that ran the earlier version: drop content_digest_log + player.content_digest_frequency and
        // re-run it (doctrine:migrations:execute --down, then --up).
        return 'Activity digest: log + run tables, player.content_digest_frequency (default weekly)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_digest_log (id UUID NOT NULL, frequency VARCHAR(255) NOT NULL, period_key VARCHAR(255) NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, had_activity BOOLEAN NOT NULL, status VARCHAR(255) NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_332B10D299E6F5DF ON content_digest_log (player_id)');
        $this->addSql('CREATE INDEX IDX_332B10D299E6F5DF786069FF ON content_digest_log (player_id, had_activity)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_332B10D299E6F5DF80C3B793 ON content_digest_log (player_id, period_key)');
        $this->addSql('CREATE TABLE content_digest_run (id UUID NOT NULL, kind VARCHAR(255) NOT NULL, frequency VARCHAR(255) NOT NULL, period_key VARCHAR(255) NOT NULL, recipients INT NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, spacing_seconds INT NOT NULL, sent INT DEFAULT 0 NOT NULL, failed_permanently INT DEFAULT 0 NOT NULL, deferred INT DEFAULT 0 NOT NULL, stopped_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EC62DCD780C3B793 ON content_digest_run (period_key)');
        $this->addSql('ALTER TABLE content_digest_log ADD CONSTRAINT FK_332B10D299E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD content_digest_frequency VARCHAR(255) DEFAULT \'weekly\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_digest_log DROP CONSTRAINT FK_332B10D299E6F5DF');
        $this->addSql('DROP TABLE content_digest_log');
        $this->addSql('DROP TABLE content_digest_run');
        $this->addSql('ALTER TABLE player DROP content_digest_frequency');
    }
}
