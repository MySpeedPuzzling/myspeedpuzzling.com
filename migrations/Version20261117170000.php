<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Leaner indexes on notification, player_rating_snapshot and solving_time_prediction; vacuum puzzle_solving_time
 * every ~2 % of changed rows (docs/database-indexes.md).
 *
 * notification: Doctrine indexes every foreign key column, so each of the fifteen nullable "what is it about" columns
 * below had a full b-tree - NULLs included - while 99.6-100 % of the 2.0M rows are NULL there (production
 * 2026-11-17: 0-6,626 non-NULL values per column). ~485 MB of index, maintained on every insert, eleven of them never
 * scanned in 15 days (2026-11-15). Each becomes a partial index of the rows that have a value: `col = $1` (the
 * notifications page's branches, the ON DELETE CASCADE / SET NULL lookup when the referenced row is deleted) implies
 * `col IS NOT NULL`, so the planner uses it for all of them - dropping the indexes outright would make every such
 * delete scan the whole table. player_id (never NULL) and target_solving_time_id (99 % filled) keep their full index.
 *
 * player_rating_snapshot (player_id) and solving_time_prediction (time_id): the first column of a unique index
 * (player_id, pieces_count, snapshot_date) / (time_id, predictor, model_version) - and of (player_id, snapshot_date) -
 * which serves the same lookups. 56 + 60 MB.
 *
 * Doctrine does not notice the missing foreign key indexes: reading the database schema back, DBAL adds the same
 * implicit index for every foreign key that no index covers (Table::_addForeignKeyConstraint()), so both sides of the
 * comparison carry it and doctrine:schema:validate / migrations:diff stay clean. The partial indexes are custom_*,
 * hidden from Doctrine as usual. tests/bootstrap.php mirrors both, as schema:create still builds the full ones.
 *
 * Production runs docs/database-indexes-notification-fk-prestep.sql first: the fifteen partial indexes built
 * CONCURRENTLY, so the CREATE INDEX IF NOT EXISTS below only finds them (without the pre-step they are built here, under
 * a SHARE lock on notification - writes wait - for the length of the build). A CONCURRENTLY build that failed leaves
 * an INVALID index of the same name, which IF NOT EXISTS would skip - the check before the drops refuses to go on
 * then, instead of leaving the foreign keys without an index.
 *
 * Last in the batch on purpose: DROP INDEX takes an ACCESS EXCLUSIVE lock on the table (reads wait too), which the
 * all-or-nothing boot migration keeps until its commit. The drops are metadata only, but they wait for transactions
 * already using the table - lock_timeout caps that at 5 s (Version20260930163200).
 *
 * puzzle_solving_time: vacuum every ~2 % of changed or inserted rows (~12k of ~595k) instead of the server's 5 %
 * (~30k, which the table rarely reaches) - the index-only scans of the insights batch and the Times boards fetched 66 % of their rows from the heap
 * (docs/TODO.md "Background batch queries"). The analyze setting (1 %, Version20261111130000) stays. ALTER TABLE ...
 * SET takes a SHARE UPDATE EXCLUSIVE lock: reads and writes go on.
 */
final class Version20261117170000 extends AbstractMigration
{
    /**
     * notification column => the Doctrine foreign key index it replaces
     */
    private const array NOTIFICATION_PARTIAL_INDEXES = [
        'actor_player_id' => 'idx_bf5476ca6d9f05f3',
        'target_borrow_request_id' => 'idx_bf5476ca4f71a703',
        'target_change_request_id' => 'idx_bf5476ca96566c4c',
        'target_competition_round_id' => 'idx_bf5476cafd44e32c',
        'target_conversation_id' => 'idx_bf5476ca4ba2e1f5',
        'target_data_import_batch_id' => 'idx_bf5476ca45b56d34',
        'target_data_import_undo_id' => 'idx_bf5476cacacb171d',
        'target_guest_link_request_id' => 'idx_bf5476ca5f704f2',
        'target_listing_request_id' => 'idx_bf5476caf5730683',
        'target_merge_request_id' => 'idx_bf5476cafb320824',
        'target_partner_listing_id' => 'idx_bf5476caff157ae',
        'target_partner_request_id' => 'idx_bf5476ca99ee7211',
        'target_puzzling_team_id' => 'idx_bf5476caa3e76637',
        'target_sold_swapped_item_id' => 'idx_bf5476ca343e61b1',
        'target_transfer_id' => 'idx_bf5476cad44a07a5',
    ];

    public function getDescription(): string
    {
        return 'Partial indexes for the notification foreign keys, drop two prefix indexes, vacuum puzzle_solving_time every ~2 %';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");

        $this->addSql('ALTER TABLE puzzle_solving_time SET (autovacuum_vacuum_scale_factor = 0.02, autovacuum_vacuum_insert_scale_factor = 0.02)');

        foreach (array_keys(self::NOTIFICATION_PARTIAL_INDEXES) as $column) {
            $this->addSql(sprintf(
                'CREATE INDEX IF NOT EXISTS custom_notification_fk_%1$s ON notification (%1$s) WHERE %1$s IS NOT NULL',
                $column,
            ));
        }

        $partialIndexNames = implode(', ', array_map(
            static fn (string $column): string => "'custom_notification_fk_{$column}'",
            array_keys(self::NOTIFICATION_PARTIAL_INDEXES),
        ));

        $this->addSql(<<<SQL
DO $$
DECLARE
    broken text;
BEGIN
    SELECT string_agg(index_class.relname, ', ') INTO broken
    FROM pg_index
    JOIN pg_class index_class ON index_class.oid = pg_index.indexrelid
    WHERE index_class.relname IN ({$partialIndexNames})
        AND NOT (pg_index.indisvalid AND pg_index.indisready);

    IF broken IS NOT NULL THEN
        RAISE EXCEPTION 'Invalid index left by a failed CREATE INDEX CONCURRENTLY: %. DROP INDEX CONCURRENTLY it and build it again (docs/database-indexes-notification-fk-prestep.sql)', broken;
    END IF;
END
$$
SQL);

        foreach (self::NOTIFICATION_PARTIAL_INDEXES as $replacedIndex) {
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $replacedIndex));
        }

        // player_rating_snapshot (player_id) and solving_time_prediction (time_id)
        $this->addSql('DROP INDEX IF EXISTS idx_ab6a238999e6f5df');
        $this->addSql('DROP INDEX IF EXISTS idx_b40d9d315eeadd3b');

        $this->addSql('SET LOCAL lock_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ab6a238999e6f5df ON player_rating_snapshot (player_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_b40d9d315eeadd3b ON solving_time_prediction (time_id)');

        foreach (self::NOTIFICATION_PARTIAL_INDEXES as $column => $replacedIndex) {
            $this->addSql(sprintf('CREATE INDEX IF NOT EXISTS %s ON notification (%s)', $replacedIndex, $column));
            $this->addSql(sprintf('DROP INDEX IF EXISTS custom_notification_fk_%s', $column));
        }

        $this->addSql('ALTER TABLE puzzle_solving_time RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_insert_scale_factor)');
    }
}
