-- Production pre-step for Version20261117170000 (docs/database-indexes.md, "notification foreign keys").
--
-- Run BEFORE deploying the release that contains the migration. Builds the fifteen partial indexes without blocking
-- writes, so the migration's CREATE INDEX IF NOT EXISTS only finds them and the boot transaction stays short.
--
-- CREATE INDEX CONCURRENTLY cannot run inside a transaction block: run this file in psql's default autocommit mode,
-- each statement its own transaction - never with -1 / --single-transaction, never wrapped in BEGIN, never as one
-- multi-statement `psql -c` string:
--
--   ssh root@lily.srv.thedevs.cz 'docker exec -i myspeedpuzzling-db-1 psql -U speedpuzzling -d speedpuzzling -v ON_ERROR_STOP=1' \
--       < docs/database-indexes-notification-fk-prestep.sql
--
-- Each build reads the whole notification heap twice (~620 MB on 2026-11-17) and waits for the transactions running
-- at its start; expect seconds each. Reads and writes go on. Re-running the file is safe (IF NOT EXISTS).
--
-- If a build fails (cancelled, lock timeout, deadlock), it leaves an INVALID index of that name, which IF NOT EXISTS
-- then skips - the migration refuses to drop the old indexes while one is invalid. Recover with
--   DROP INDEX CONCURRENTLY IF EXISTS custom_notification_fk_<column>;
-- and run this file again. Then run the verification query at the end: 15 rows, all valid and ready.

SET maintenance_work_mem = '1GB';

CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_actor_player_id ON notification (actor_player_id) WHERE actor_player_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_borrow_request_id ON notification (target_borrow_request_id) WHERE target_borrow_request_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_change_request_id ON notification (target_change_request_id) WHERE target_change_request_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_competition_round_id ON notification (target_competition_round_id) WHERE target_competition_round_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_conversation_id ON notification (target_conversation_id) WHERE target_conversation_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_data_import_batch_id ON notification (target_data_import_batch_id) WHERE target_data_import_batch_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_data_import_undo_id ON notification (target_data_import_undo_id) WHERE target_data_import_undo_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_guest_link_request_id ON notification (target_guest_link_request_id) WHERE target_guest_link_request_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_listing_request_id ON notification (target_listing_request_id) WHERE target_listing_request_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_merge_request_id ON notification (target_merge_request_id) WHERE target_merge_request_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_partner_listing_id ON notification (target_partner_listing_id) WHERE target_partner_listing_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_partner_request_id ON notification (target_partner_request_id) WHERE target_partner_request_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_puzzling_team_id ON notification (target_puzzling_team_id) WHERE target_puzzling_team_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_sold_swapped_item_id ON notification (target_sold_swapped_item_id) WHERE target_sold_swapped_item_id IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS custom_notification_fk_target_transfer_id ON notification (target_transfer_id) WHERE target_transfer_id IS NOT NULL;

-- Verification: expect 15 rows, every one valid = t and ready = t, the definition ending in WHERE (<column> IS NOT NULL)
SELECT index_class.relname AS index_name,
    pg_index.indisvalid AS valid,
    pg_index.indisready AS ready,
    pg_size_pretty(pg_relation_size(index_class.oid)) AS size,
    pg_get_indexdef(index_class.oid) AS definition
FROM pg_index
JOIN pg_class index_class ON index_class.oid = pg_index.indexrelid
WHERE pg_index.indrelid = 'notification'::regclass
    AND index_class.relname LIKE 'custom\_notification\_fk\_%'
ORDER BY index_class.relname;
