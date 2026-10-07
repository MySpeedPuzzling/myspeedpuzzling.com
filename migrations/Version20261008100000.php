<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two small partial indexes on puzzle for the review queues' backlog badges in the key menu (GetAdminQueueCounts,
 * docs/database-indexes.md): without them both counts read every puzzle - the merge requests' secrecy check only to
 * find the few puzzles with a hide date, the approvals to find the unapproved ones (production 2026-10-08: ~8 ms +
 * ~5 ms; with them ~0.15 ms each on a copy of production).
 */
final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial indexes on puzzle for hidden and unapproved puzzles (review queue counts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_puzzle_hidden ON puzzle (id) WHERE hide_until IS NOT NULL OR hide_image_until IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_puzzle_unapproved ON puzzle (id) INCLUDE (hide_until, hide_image_until) WHERE approved = false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_hidden');
        $this->addSql('DROP INDEX IF EXISTS custom_puzzle_unapproved');
    }
}
