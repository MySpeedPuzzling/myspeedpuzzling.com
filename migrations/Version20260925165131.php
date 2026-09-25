<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925165131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle moderation decision log + backfill of already reviewed change and merge requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_moderation_decision (id UUID NOT NULL, action VARCHAR(255) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decided_by_id UUID NOT NULL, decided_by_name VARCHAR(255) DEFAULT NULL, decided_by_code VARCHAR(255) DEFAULT NULL, source VARCHAR(255) NOT NULL, puzzle_id UUID DEFAULT NULL, puzzle_name VARCHAR(255) DEFAULT NULL, change_request_id UUID DEFAULT NULL, merge_request_id UUID DEFAULT NULL, manufacturer_id UUID DEFAULT NULL, note TEXT DEFAULT NULL, details JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DA11B82D57A5F592 ON puzzle_moderation_decision (decided_at)');
        $this->addSql('CREATE INDEX IDX_DA11B82DE26B496B ON puzzle_moderation_decision (decided_by_id)');
        $this->addSql('CREATE INDEX IDX_DA11B82DD9816812 ON puzzle_moderation_decision (puzzle_id)');

        // Hand-written backfill: every change / merge request decided so far, so the log
        // starts complete. The reviewer's name and code are copied as they are today.
        $this->addSql(<<<'SQL'
INSERT INTO puzzle_moderation_decision (id, action, decided_at, decided_by_id, decided_by_name, decided_by_code, source, puzzle_id, puzzle_name, change_request_id, note, details)
SELECT
    gen_random_uuid(),
    CASE r.status WHEN 'approved' THEN 'change_request_approved' ELSE 'change_request_rejected' END,
    r.reviewed_at,
    r.reviewed_by_id,
    reviewer.name,
    reviewer.code,
    'admin_ui',
    r.puzzle_id,
    COALESCE(p.name, r.original_name),
    r.id,
    r.rejection_reason,
    '{"backfilled": true}'::json
FROM puzzle_change_request r
JOIN player reviewer ON reviewer.id = r.reviewed_by_id
LEFT JOIN puzzle p ON p.id = r.puzzle_id
WHERE r.status IN ('approved', 'rejected') AND r.reviewed_at IS NOT NULL
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO puzzle_moderation_decision (id, action, decided_at, decided_by_id, decided_by_name, decided_by_code, source, puzzle_id, puzzle_name, merge_request_id, note, details)
SELECT
    gen_random_uuid(),
    CASE r.status WHEN 'approved' THEN 'merge_request_approved' ELSE 'merge_request_rejected' END,
    r.reviewed_at,
    r.reviewed_by_id,
    reviewer.name,
    reviewer.code,
    COALESCE((SELECT a.decision_source FROM puzzle_merge_audit a WHERE a.merge_request_id = r.id ORDER BY a.performed_at DESC LIMIT 1), 'admin_ui'),
    COALESCE(r.survivor_puzzle_id, r.source_puzzle_id),
    COALESCE(survivor.name, r.source_puzzle_name),
    r.id,
    COALESCE(r.rejection_reason, (SELECT a.decision_note FROM puzzle_merge_audit a WHERE a.merge_request_id = r.id ORDER BY a.performed_at DESC LIMIT 1)),
    json_build_object(
        'backfilled', true,
        'survivorPuzzleId', r.survivor_puzzle_id,
        'mergedPuzzleIds', r.merged_puzzle_ids,
        'reportedDuplicatePuzzleIds', r.reported_duplicate_puzzle_ids
    )
FROM puzzle_merge_request r
JOIN player reviewer ON reviewer.id = r.reviewed_by_id
LEFT JOIN puzzle survivor ON survivor.id = r.survivor_puzzle_id
WHERE r.status IN ('approved', 'rejected') AND r.reviewed_at IS NOT NULL
SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE puzzle_moderation_decision');
    }
}
