<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916150951 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit trail for approved puzzle merges: before/after snapshots so a merge can be reviewed and unpicked.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE puzzle_merge_audit (id UUID NOT NULL, merge_request_id UUID NOT NULL, survivor_puzzle_id UUID NOT NULL, performed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decision_source VARCHAR(255) NOT NULL, snapshot_before JSON NOT NULL, snapshot_after JSON NOT NULL, decision_note TEXT DEFAULT NULL, decision_confidence VARCHAR(16) DEFAULT NULL, performed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_576DE2BF2E65C292 ON puzzle_merge_audit (performed_by_id)');
        $this->addSql('CREATE INDEX idx_puzzle_merge_audit_merge_request ON puzzle_merge_audit (merge_request_id)');
        $this->addSql('CREATE INDEX idx_puzzle_merge_audit_performed_at ON puzzle_merge_audit (performed_at)');
        $this->addSql('ALTER TABLE puzzle_merge_audit ADD CONSTRAINT FK_576DE2BF2E65C292 FOREIGN KEY (performed_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_merge_audit DROP CONSTRAINT FK_576DE2BF2E65C292');
        $this->addSql('DROP TABLE puzzle_merge_audit');
    }
}
