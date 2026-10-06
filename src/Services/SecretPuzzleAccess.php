<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;

/**
 * A puzzle a competition keeps secret does not exist for anybody but its organisers - its pages (detail, suggest a
 * change, report a duplicate, QR codes, marketplace, ...) answer 404 and nothing can be done with it (a time, a
 * collection, a listing, a loan, an EAN) - also by someone who has its id. Its organisers (admins, whoever added it,
 * maintainers of a competition with the puzzle in a round) still see and use it.
 *
 * "Kept secret by a competition" = PuzzleSecrecy (a competition's puzzle, hidden) - by default only while the puzzle
 * itself is hidden (hide_until); the strict pages (codes) also while only its picture is. An approved placeholder hidden
 * by hand (Ravensburger Puzzle Month - its box link must work) is not.
 */
readonly final class SecretPuzzleAccess
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private GetCompetitionPermissions $getCompetitionPermissions,
    ) {
    }

    /**
     * @param bool $alsoWhileImageHidden pages that show or prefill the puzzle's codes (a change proposal) - with "hide
     *                                   image only" the codes give the box away
     *
     * @throws PuzzleNotFound
     */
    public function assertVisible(string $puzzleId, bool $alsoWhileImageHidden = false): void
    {
        if ($this->isHiddenFromViewer($puzzleId, $alsoWhileImageHidden)) {
            throw new PuzzleNotFound();
        }
    }

    /**
     * For handlers - the player the message acts for, whoever sent it (web form, API, multiscan).
     *
     * @throws PuzzleNotFound
     */
    public function assertUsableBy(string $puzzleId, null|string $playerId): void
    {
        if ($this->isHiddenFromPlayer($puzzleId, $playerId)) {
            throw new PuzzleNotFound();
        }
    }

    /**
     * The same for a loaded puzzle - no query at all while the puzzle is not hidden (every time, collection, listing
     * of a public puzzle).
     *
     * @throws PuzzleNotFound
     */
    public function assertPuzzleUsableBy(Puzzle $puzzle, null|string $playerId): void
    {
        if ($puzzle->isHiddenAt($this->clock->now()) === false) {
            return;
        }

        $this->assertUsableBy($puzzle->id->toString(), $playerId);
    }

    public function isHiddenFromViewer(string $puzzleId, bool $alsoWhileImageHidden = false): bool
    {
        return $this->isHiddenFromPlayer(
            $puzzleId,
            $this->retrieveLoggedUserProfile->getProfile()?->playerId,
            $alsoWhileImageHidden,
        );
    }

    public function isHiddenFromPlayer(string $puzzleId, null|string $playerId, bool $alsoWhileImageHidden = false): bool
    {
        if (Uuid::isValid($puzzleId) === false) {
            return false;
        }

        $secret = PuzzleSecrecy::sqlSecret('p');
        $nameHidden = $alsoWhileImageHidden ? 'true' : '(p.hide_until IS NOT NULL AND p.hide_until > :now::timestamp)';

        /** @var false|array{competition_ids: null|string, added_by: null|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    p.added_by_user_id::text AS added_by,
    (
        SELECT string_agg(DISTINCT cr.competition_id::text, ',')
        FROM competition_round_puzzle crp
        INNER JOIN competition_round cr ON cr.id = crp.round_id
        WHERE crp.puzzle_id = p.id
    ) AS competition_ids
FROM puzzle p
WHERE p.id = :puzzleId
    AND {$secret}
    AND {$nameHidden}
SQL,
            [
                'puzzleId' => $puzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        if ($row === false) {
            return false;
        }

        if ($playerId === null || Uuid::isValid($playerId) === false) {
            return true;
        }

        // Admins, and whoever added it (a puzzle removed from its round has no competition left to ask)
        if ($row['added_by'] === $playerId || $this->isAdmin($playerId)) {
            return false;
        }

        $permissions = $this->getCompetitionPermissions->forPlayer($playerId);

        foreach (array_filter(explode(',', $row['competition_ids'] ?? '')) as $competitionId) {
            if ($permissions->canEditCompetition($competitionId)) {
                return false;
            }
        }

        return true;
    }

    private function isAdmin(string $playerId): bool
    {
        return $this->database->fetchOne(
            'SELECT 1 FROM player WHERE id = :playerId AND is_admin = true',
            ['playerId' => $playerId],
        ) !== false;
    }
}
