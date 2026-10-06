<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * A puzzle a competition keeps secret does not exist for anybody but its organisers - its pages (detail, suggest a
 * change, report a duplicate, QR codes, marketplace, ...) answer 404 and nothing can be done with it (a time, a
 * collection, a listing, a loan, an EAN, a stopwatch) - also by someone who has its id. Its organisers (admins,
 * whoever added it, maintainers of a competition with the puzzle in a round) still see it and prepare the event with
 * it, but until its reveal nobody - organisers included - records anything personal on it (assertWritableBy()).
 *
 * "Kept secret by a competition" = PuzzleSecrecy (a competition's puzzle, hidden) - by default only while the puzzle
 * itself is hidden (hide_until); the strict checks (codes, EANs, rounds) also while only its picture is. An approved
 * placeholder hidden by hand (Ravensburger Puzzle Month - its box link must work) is not.
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
    public function assertUsableBy(string $puzzleId, null|string $playerId, bool $alsoWhileImageHidden = false): void
    {
        if ($this->isHiddenFromPlayer($puzzleId, $playerId, $alsoWhileImageHidden)) {
            throw new PuzzleNotFound();
        }
    }

    /**
     * The same for a loaded puzzle - no query at all while the puzzle is not hidden (every time, collection, listing
     * of a public puzzle).
     *
     * @throws PuzzleNotFound
     */
    public function assertPuzzleUsableBy(Puzzle $puzzle, null|string $playerId, bool $alsoWhileImageHidden = false): void
    {
        $now = $this->clock->now();

        if (($alsoWhileImageHidden ? $puzzle->isImageHiddenAt($now) : $puzzle->isHiddenAt($now)) === false) {
            return;
        }

        $this->assertUsableBy($puzzle->id->toString(), $playerId, $alsoWhileImageHidden);
    }

    /**
     * Personal records - a time (also relax / tracking, a saved stopwatch, the API), a collection, the wishlist, a
     * sell/swap listing, lending or borrowing: refused for everybody until the reveal. Somebody the puzzle is hidden
     * from does not learn it exists (PuzzleNotFound); its organisers are told when it opens (PuzzleNotRevealedYet).
     *
     * @throws PuzzleNotFound
     * @throws PuzzleNotRevealedYet
     */
    public function assertWritableBy(string $puzzleId, null|string $playerId): void
    {
        $row = $this->secretRow($puzzleId, false);

        if ($row === null) {
            return;
        }

        if ($this->isHiddenFor($row, $playerId)) {
            throw new PuzzleNotFound();
        }

        throw self::refusal($puzzleId, $row);
    }

    /**
     * When a puzzle a competition keeps secret opens for personal records - null when nothing keeps it. Says nothing
     * about who may see it (isHiddenFromViewer()); for pages that already show the puzzle and want to say when.
     */
    public function pendingReveal(string $puzzleId): null|PuzzleNotRevealedYet
    {
        $row = $this->secretRow($puzzleId, false);

        return $row === null ? null : self::refusal($puzzleId, $row);
    }

    /**
     * The same for a loaded puzzle - no query at all while the puzzle is not hidden.
     *
     * @throws PuzzleNotFound
     * @throws PuzzleNotRevealedYet
     */
    public function assertPuzzleWritableBy(Puzzle $puzzle, null|string $playerId): void
    {
        if ($puzzle->isHiddenAt($this->clock->now()) === false) {
            return;
        }

        $this->assertWritableBy($puzzle->id->toString(), $playerId);
    }

    /**
     * For a web page: the signed-in viewer.
     *
     * @throws PuzzleNotFound
     * @throws PuzzleNotRevealedYet
     */
    public function assertWritableByViewer(string $puzzleId): void
    {
        $this->assertWritableBy($puzzleId, $this->retrieveLoggedUserProfile->getProfile()?->playerId);
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
        $row = $this->secretRow($puzzleId, $alsoWhileImageHidden);

        return $row !== null && $this->isHiddenFor($row, $playerId);
    }

    /**
     * The puzzle when a competition keeps it secret now - with who may see it and the round its reveal waits for.
     *
     * @return null|array{
     *     added_by: null|string,
     *     competition_ids: null|string,
     *     hide_until: null|string,
     *     round_timezone: null|string,
     *     competition_country: null|string,
     *     series_country: null|string,
     * }
     */
    private function secretRow(string $puzzleId, bool $alsoWhileImageHidden): null|array
    {
        if (Uuid::isValid($puzzleId) === false) {
            return null;
        }

        $secret = PuzzleSecrecy::sqlSecret('p');
        $nameHidden = $alsoWhileImageHidden ? 'true' : '(p.hide_until IS NOT NULL AND p.hide_until > :now::timestamp)';
        $revealAt = RoundPuzzleReveal::sqlRevealAt('crp', 'cr');

        /**
         * @var false|array{
         *     added_by: null|string,
         *     competition_ids: null|string,
         *     hide_until: null|string,
         *     round_timezone: null|string,
         *     competition_country: null|string,
         *     series_country: null|string,
         * } $row
         */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    p.added_by_user_id::text AS added_by,
    p.hide_until,
    (
        SELECT string_agg(DISTINCT cr.competition_id::text, ',')
        FROM competition_round_puzzle crp
        INNER JOIN competition_round cr ON cr.id = crp.round_id
        WHERE crp.puzzle_id = p.id
    ) AS competition_ids,
    reveal_round.timezone AS round_timezone,
    reveal_round.competition_country,
    reveal_round.series_country
FROM puzzle p
LEFT JOIN LATERAL (
    -- The round whose reveal the name waits for: the latest of those hiding it entirely on the whole site (hide_until
    -- is theirs - an "image only" round may reveal later, but not the name)
    SELECT cr.timezone, c.location_country_code AS competition_country, cs.location_country_code AS series_country
    FROM competition_round_puzzle crp
    INNER JOIN competition_round cr ON cr.id = crp.round_id
    INNER JOIN competition c ON c.id = cr.competition_id
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    WHERE crp.puzzle_id = p.id AND crp.hide_until_round_starts AND crp.hides_everywhere
        AND COALESCE(crp.hide_mode, 'entirely') = 'entirely'
    ORDER BY {$revealAt} DESC NULLS FIRST
    LIMIT 1
) reveal_round ON true
WHERE p.id = :puzzleId
    AND {$secret}
    AND {$nameHidden}
SQL,
            [
                'puzzleId' => $puzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        return $row === false ? null : $row;
    }

    /**
     * @param array{hide_until: null|string, round_timezone: null|string, competition_country: null|string, series_country: null|string, ...} $row
     */
    private static function refusal(string $puzzleId, array $row): PuzzleNotRevealedYet
    {
        $revealsAt = $row['hide_until'] !== null ? new DateTimeImmutable($row['hide_until']) : null;

        return new PuzzleNotRevealedYet(
            puzzleId: $puzzleId,
            // A manual reveal not made yet is stored as the far future (CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED)
            revealsAt: $revealsAt !== null && $revealsAt < new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED) ? $revealsAt : null,
            timezone: RoundTimezone::resolve($row['round_timezone'], $row['competition_country'], $row['series_country']),
        );
    }

    /**
     * @param array{added_by: null|string, competition_ids: null|string, ...} $row
     */
    private function isHiddenFor(array $row, null|string $playerId): bool
    {
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
