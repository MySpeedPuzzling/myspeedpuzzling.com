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
 * Claims images for a worker in ONE statement - a deliberate exception to "handlers change entities": candidates,
 * inserts and lease extensions for up to 200 puzzles are one set-based write, where loading, checking and persisting
 * entities would take hundreds of statements and leave room between the read and the insert.
 *
 * Serialisation: ClaimPuzzleImages is SerializedByLock under the key every image analysis write takes
 * (LockUntilCommittedMiddleware holds it until the commit) - that is the guarantee two claims, or a claim and a store,
 * never interleave. The statement stays safe without it, as a backstop:
 * - candidates are locked FOR NO KEY UPDATE SKIP LOCKED: unlike FOR UPDATE it never blocks rows referencing the puzzle
 *   being inserted (their foreign key checks take KEY SHARE); a puzzle edit at the same moment waits milliseconds, a
 *   puzzle row an edit already holds is skipped;
 * - INSERT … ON CONFLICT (analyzer, puzzle_id) DO UPDATE only WHERE the existing claim is expired or this worker's: the
 *   conflict check sees claims committed after this statement's snapshot, so another worker's claim is never taken
 *   over; RETURNING gives exactly what this worker now holds.
 *
 * Order: fresh work first (puzzle id order), this worker's own unexpired claims after it - a worker that skipped or
 * failed images it still holds keeps advancing instead of getting the same ones back first; its own claims come back
 * (and are extended) only when there is not enough fresh work to fill `limit`.
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
    ORDER BY (c.id IS NOT NULL AND c.worker = :worker AND c.expires_at > :now), p.id
    LIMIT :limit
    FOR NO KEY UPDATE OF p SKIP LOCKED
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
