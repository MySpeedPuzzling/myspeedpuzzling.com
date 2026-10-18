<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ClaimPuzzleImages;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Claims images for a worker in ONE statement - a deliberate exception to "handlers change entities": a claim must be
 * taken atomically against another machine claiming at the same moment, which loading entities, checking and
 * persisting them cannot guarantee (two workers would read the same free puzzles and both insert). So:
 *
 * - candidates: puzzles with a shown image still to be analysed (no analysis, another picture or another version) and
 *   no claim held by another worker (none, expired, or this worker's own), in id order, locked FOR UPDATE SKIP LOCKED -
 *   a concurrent claim skips the rows this one is taking instead of returning the same ones;
 * - INSERT … ON CONFLICT (analyzer, puzzle_id) DO UPDATE only WHERE the existing claim is expired or this worker's:
 *   the final guard - the conflict check sees claims committed after this statement's snapshot, so a claim another
 *   worker committed meanwhile is never taken over; RETURNING gives exactly what this worker now holds.
 *
 * Ids are uuid7 from PHP, one per candidate (paired by position); a re-claimed row keeps its id.
 */
#[AsMessageHandler]
readonly final class ClaimPuzzleImagesHandler
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> the claimed puzzle ids, in id order
     */
    public function __invoke(ClaimPuzzleImages $message): array
    {
        $now = $this->clock->now();
        $limit = max(1, min($message->limit, ClaimPuzzleImages::MAX_LIMIT));
        $ids = [];

        for ($i = 0; $i < $limit; $i++) {
            $ids[] = Uuid::uuid7()->toString();
        }

        $fingerprint = PuzzleImageFingerprint::sql('p.image');

        $claimed = $this->database->executeQuery(
            <<<SQL
WITH candidates AS (
    SELECT p.id
    FROM puzzle p
    LEFT JOIN puzzle_image_analysis a ON a.puzzle_id = p.id AND a.analyzer = :analyzer
    LEFT JOIN puzzle_image_analysis_claim c ON c.puzzle_id = p.id AND c.analyzer = :analyzer
    WHERE p.image IS NOT NULL
        AND (p.hide_until IS NULL OR p.hide_until <= :now)
        AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
        AND (a.id IS NULL OR a.image_fingerprint <> {$fingerprint} OR a.analyzer_version <> :version)
        AND (c.id IS NULL OR c.expires_at <= :now OR c.worker = :worker)
    ORDER BY p.id
    LIMIT :limit
    FOR UPDATE OF p SKIP LOCKED
),
numbered AS (
    SELECT id, row_number() OVER (ORDER BY id) AS position FROM candidates
),
claim_ids AS (
    SELECT claim_id, position FROM unnest(CAST(:ids AS uuid[])) WITH ORDINALITY AS t(claim_id, position)
)
INSERT INTO puzzle_image_analysis_claim (id, puzzle_id, analyzer, worker, claimed_at, expires_at)
SELECT claim_ids.claim_id, numbered.id, CAST(:analyzer AS VARCHAR), CAST(:worker AS VARCHAR), CAST(:now AS TIMESTAMP), CAST(:expiresAt AS TIMESTAMP)
FROM numbered
JOIN claim_ids ON claim_ids.position = numbered.position
ON CONFLICT (analyzer, puzzle_id) DO UPDATE
    SET worker = EXCLUDED.worker, claimed_at = EXCLUDED.claimed_at, expires_at = EXCLUDED.expires_at
    WHERE puzzle_image_analysis_claim.expires_at <= :now OR puzzle_image_analysis_claim.worker = EXCLUDED.worker
RETURNING puzzle_id
SQL,
            [
                'analyzer' => $message->analyzer->value,
                'version' => $message->version,
                'worker' => $message->worker,
                'now' => $now->format('Y-m-d H:i:s'),
                'expiresAt' => $now->modify(sprintf('+%d seconds', $message->leaseSeconds))->format('Y-m-d H:i:s'),
                'limit' => $limit,
                'ids' => '{' . implode(',', $ids) . '}',
            ],
        )->fetchFirstColumn();

        $puzzleIds = array_values(array_filter($claimed, is_string(...)));
        sort($puzzleIds);

        return $puzzleIds;
    }
}
