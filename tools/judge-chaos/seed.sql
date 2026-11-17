-- The judge-chaos round (tools/judge-chaos/README.md "Data") - run on the THROWAWAY copy only (chaos.sh refuses any
-- other database). Made-up names; ids are readable on purpose (…0001-000000000NNN = participant NNN, …0002-… its entry).
--
-- In the fixtures' Results Cup (OfficialResultsFixture - organiser PLAYER_WITH_STRIPE "Sarah Williams"):
--   round "Chaos Round" (solo, PUZZLE_6000, limit 600 min, its stopwatch running since 10 minutes ago, tables used)
--   300 entrants "Chaos Tester 001..300" at tables 1..300, no results
--   referee PLAYER_WITH_FAVORITES "Michael Johnson"
--   competitor pages + time proposals on; entrant 300 linked to PLAYER_REGULAR "John Doe", 299 to PLAYER_PRIVATE
--   "Jane Smith", 298 to PLAYER_ADMIN "Admin User" (linked a day ago - before the stopwatch's first start, so they may propose)
--   every fixture account gets the password `judge-chaos-1` (argon2id)
-- Idempotent: running it again resets the round (results, receipts, proposals) and the stopwatch.

\set ON_ERROR_STOP on
\set competition '018d0020-0000-0000-0000-000000000001'
\set round '018d0099-0000-0000-0000-000000000001'

BEGIN;

DELETE FROM competition_time_proposal WHERE round_id = :'round';
DELETE FROM round_result_change_receipt WHERE round_id = :'round';
DELETE FROM competition_participant_round WHERE round_id = :'round';
DELETE FROM competition_round_puzzle WHERE round_id = :'round';
DELETE FROM competition_round WHERE id = :'round';
DELETE FROM competition_participant WHERE id::text LIKE '018d0099-0000-0000-0001-%';
DELETE FROM competition_referee WHERE competition_id = :'competition';

INSERT INTO competition_round (id, competition_id, name, slug, category, puzzle_format, minutes_limit, starts_at, timezone,
                               stopwatch_status, stopwatch_started_at, stopwatch_first_started_at, table_numbers_off,
                               reveal_delay_minutes, start_time_known)
VALUES (:'round', :'competition', 'Chaos Round', 'chaos-round', 'solo', 'total', 600,
        (now() AT TIME ZONE 'UTC') - interval '10 minutes', 'Europe/Prague',
        'running', (now() AT TIME ZONE 'UTC') - interval '10 minutes', (now() AT TIME ZONE 'UTC') - interval '10 minutes',
        false, 10, true);

INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, position, hide_until_round_starts, reveal_mode, hides_everywhere)
VALUES ('018d0099-0000-0000-0000-000000000002', :'round', '018d0003-0000-0000-0000-000000000018', 1, false, 'automatic', false);

INSERT INTO competition_participant (id, competition_id, name, country, source)
SELECT ('018d0099-0000-0000-0001-' || lpad(i::text, 12, '0'))::uuid, :'competition',
       'Chaos Tester ' || lpad(i::text, 3, '0'), (ARRAY['cz', 'de', 'sk', 'us', 'pl'])[1 + i % 5], 'manual'
FROM generate_series(1, 300) AS i;

INSERT INTO competition_participant_round (id, participant_id, round_id, table_number, result_did_not_start)
SELECT ('018d0099-0000-0000-0002-' || lpad(i::text, 12, '0'))::uuid,
       ('018d0099-0000-0000-0001-' || lpad(i::text, 12, '0'))::uuid, :'round', i, false
FROM generate_series(1, 300) AS i;

-- The competitors (linked a day ago, before the round's first start)
UPDATE competition_participant SET player_id = '018d0000-0000-0000-0000-000000000001',
       connected_at = (now() AT TIME ZONE 'UTC') - interval '1 day'
WHERE id = '018d0099-0000-0000-0001-000000000300';
UPDATE competition_participant SET player_id = '018d0000-0000-0000-0000-000000000002',
       connected_at = (now() AT TIME ZONE 'UTC') - interval '1 day'
WHERE id = '018d0099-0000-0000-0001-000000000299';
UPDATE competition_participant SET player_id = '018d0000-0000-0000-0000-000000000003',
       connected_at = (now() AT TIME ZONE 'UTC') - interval '1 day'
WHERE id = '018d0099-0000-0000-0001-000000000298';

INSERT INTO competition_referee (id, competition_id, player_id, added_by_id, added_at)
VALUES ('018d0099-0000-0000-0003-000000000001', :'competition', '018d0000-0000-0000-0000-000000000004',
        '018d0000-0000-0000-0000-000000000005', now() AT TIME ZONE 'UTC');

UPDATE competition SET competitor_pages = true, time_proposals = true WHERE id = :'competition';

-- No other round of the event runs (the current round is the chaos one)
UPDATE competition_round SET stopwatch_status = CASE WHEN stopwatch_status = 'running' THEN 'finished' ELSE stopwatch_status END
WHERE competition_id = :'competition' AND id <> :'round';

UPDATE user_account SET password = '$argon2id$v=19$m=65536,t=4,p=1$NFdSanlXbzA3WFczeUZicw$aq9ev+yasQV4FGQiJtFhrj56gHOpGAnjPP7GIHGsy2g',
       email_verified_at = COALESCE(email_verified_at, now());

COMMIT;
