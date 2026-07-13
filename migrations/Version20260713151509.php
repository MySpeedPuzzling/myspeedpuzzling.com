<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713151509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Content digest: log table + player.content_digest_frequency (default weekly, per launch decision)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_digest_log (id UUID NOT NULL, digest_type VARCHAR(255) NOT NULL, period_key VARCHAR(255) NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, had_activity BOOLEAN NOT NULL, status VARCHAR(255) NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_332B10D299E6F5DF ON content_digest_log (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_332B10D299E6F5DF5C9B6C1080C3B793 ON content_digest_log (player_id, digest_type, period_key)');
        $this->addSql('ALTER TABLE content_digest_log ADD CONSTRAINT FK_332B10D299E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD content_digest_frequency VARCHAR(255) DEFAULT \'weekly\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_digest_log');
        $this->addSql('ALTER TABLE player DROP content_digest_frequency');
    }
}
