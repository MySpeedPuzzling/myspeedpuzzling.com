<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;

/**
 * Pages of one puzzle (detail, suggest a change, report a duplicate, QR codes, ...) answer 404 while a competition
 * keeps the puzzle secret - also to someone who has its id. Its organisers (admins and maintainers of a competition
 * with the puzzle in a round) still see it.
 *
 * "Kept secret by a competition" = hidden (hide_until in the future, or for the strict pages also hide_image_until)
 * and either in a round that keeps it hidden everywhere or unapproved (a puzzle removed from its round stays hidden).
 * An approved placeholder hidden by hand (Ravensburger Puzzle Month - its box link must work) is not.
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

    public function isHiddenFromViewer(string $puzzleId, bool $alsoWhileImageHidden = false): bool
    {
        if (Uuid::isValid($puzzleId) === false) {
            return false;
        }

        $hiddenColumn = $alsoWhileImageHidden
            ? '(p.hide_until > :now::timestamp OR p.hide_image_until > :now::timestamp)'
            : 'p.hide_until > :now::timestamp';

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
    AND {$hiddenColumn}
    AND (
        p.approved = false
        OR EXISTS (SELECT 1 FROM competition_round_puzzle crp WHERE crp.puzzle_id = p.id AND crp.hides_everywhere)
    )
SQL,
            [
                'puzzleId' => $puzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        if ($row === false) {
            return false;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return true;
        }

        // Admins, and whoever added it (a puzzle removed from its round has no competition left to ask)
        if ($profile->isAdmin || $row['added_by'] === $profile->playerId) {
            return false;
        }

        $permissions = $this->getCompetitionPermissions->forPlayer($profile->playerId);

        foreach (array_filter(explode(',', $row['competition_ids'] ?? '')) as $competitionId) {
            if ($permissions->canEditCompetition($competitionId)) {
                return false;
            }
        }

        return true;
    }
}
