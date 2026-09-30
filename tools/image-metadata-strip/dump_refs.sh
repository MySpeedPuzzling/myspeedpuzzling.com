#!/usr/bin/env bash
# READ-ONLY: every DB column that stores an object key -> db-image-refs.tsv (column|id|key).
set -euo pipefail
cd /srv/myspeedpuzzling
docker compose exec -T -e PGOPTIONS='-c default_transaction_read_only=on' db \
  psql -U speedpuzzling -d speedpuzzling -At -c "
SELECT 'puzzle.image', id::text, image FROM puzzle WHERE image IS NOT NULL
UNION ALL SELECT 'manufacturer.logo', id::text, logo FROM manufacturer WHERE logo IS NOT NULL
UNION ALL SELECT 'player.avatar', id::text, avatar FROM player WHERE avatar IS NOT NULL
UNION ALL SELECT 'competition.logo', id::text, logo FROM competition WHERE logo IS NOT NULL
UNION ALL SELECT 'competition_series.logo', id::text, logo FROM competition_series WHERE logo IS NOT NULL
UNION ALL SELECT 'puzzle_solving_time.finished_puzzle_photo', id::text, finished_puzzle_photo FROM puzzle_solving_time WHERE finished_puzzle_photo IS NOT NULL
UNION ALL SELECT 'puzzle_change_request.proposed_image', id::text, proposed_image FROM puzzle_change_request WHERE proposed_image IS NOT NULL
UNION ALL SELECT 'puzzle_change_request.original_image', id::text, original_image FROM puzzle_change_request WHERE original_image IS NOT NULL
UNION ALL SELECT 'oauth2_client_request.logo_path', id::text, logo_path FROM oauth2_client_request WHERE logo_path IS NOT NULL
" > /root/msp-exif-2026-09-30/db-image-refs.tsv </dev/null
wc -l /root/msp-exif-2026-09-30/db-image-refs.tsv
cut -d'|' -f1 /root/msp-exif-2026-09-30/db-image-refs.tsv | sort | uniq -c
