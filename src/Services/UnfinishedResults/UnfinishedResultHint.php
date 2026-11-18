<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\UnfinishedResults;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultRoundNotIdentified;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\UnfinishedRoundHint;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Whether the add/edit form asks "I didn't finish within the time limit" (docs/features/competitions-management/
 * unfinished-results-plan.md, "Refined 2026-11-16" rule 1): only when the form's competition pick + puzzle +
 * solo/pair/team resolve to a round with a time limit - by exactly the rule the save applies (UnfinishedResultRound:
 * a one-time event or edition, or a series pick whose edition is found by its puzzle). Used by the hint endpoint the
 * form asks while it is filled in, and by the controllers for the state the page is rendered with.
 *
 * Never throws - the form's values are user input; anything that does not resolve answers null (no question). Beside
 * the save's rule it answers null for an event that is not publicly visible (a draft's round names nobody may see) and
 * for a puzzle a competition keeps secret from the viewer or that takes no personal records yet (the save refuses
 * those anyway), and while the round still hides its puzzle entirely on its own pages (a public catalogue puzzle can be
 * the secret of a coming round - the answer must not confirm which round holds it).
 *
 * A puzzle in several rounds of the category (shared-puzzles-and-divisions.md §2.4 row 7, A8): a round the form names
 * (Add to my profile's entry - `$namedRoundId`) is that round while it is a candidate; otherwise the switch is offered
 * only when every candidate round has the same limit and none of them still hides the puzzle entirely (the hint names
 * them all, "Challenge / Open #1") - the save then needs evidence or a named round, else it says why
 * (UnfinishedResultRoundNotIdentified).
 *
 * The kill switch UNFINISHED_RESULTS_FORM (docs/features/feature_flags.md) off: always null - the form asks nothing
 * new (an unfinished result being edited still shows its switch, the controllers decide that).
 */
readonly final class UnfinishedResultHint
{
    public function __construct(
        private UnfinishedResultRound $unfinishedResultRound,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private ClockInterface $clock,
        private Connection $database,
        private LoggerInterface $logger,
        #[Autowire(param: 'unfinishedResultsForm')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param null|string $competitionPick the picker's value: `<uuid>`, `edition:<uuid>` or `series:<uuid>`
     * @param int $puzzlersCount the player and every co-puzzler (1 = solo, 2 = pair, more = team)
     * @param null|DateTimeImmutable $solveDay the form's day - a series pick's edition is found by its puzzle (and day)
     * @param null|string $namedRoundId the round of the official entry the form was filled in from (Add to my profile)
     */
    public function forPick(null|string $competitionPick, null|string $puzzleId, int $puzzlersCount, null|DateTimeImmutable $solveDay, null|string $namedRoundId = null): null|UnfinishedRoundHint
    {
        // Runs on every speed submit of the add/edit form with a competition: whatever goes wrong here, the form works
        // exactly as before (no question) - the add form must never break
        try {
            return $this->resolve($competitionPick, $puzzleId, $puzzlersCount, $solveDay, $namedRoundId);
        } catch (Throwable $e) {
            $this->logger->warning('Unfinished result round hint failed - the form asks nothing', [
                'exception' => $e,
            ]);

            return null;
        }
    }

    private function resolve(null|string $competitionPick, null|string $puzzleId, int $puzzlersCount, null|DateTimeImmutable $solveDay, null|string $namedRoundId): null|UnfinishedRoundHint
    {
        if ($this->enabled === false) {
            return null;
        }

        $pick = CompetitionPick::tryFrom($competitionPick);

        if ($pick === null || $puzzleId === null || Uuid::isValid($puzzleId) === false) {
            return null;
        }

        $puzzleId = strtolower($puzzleId);
        $competitionId = $pick->competitionId();

        try {
            if ($competitionId !== null && $this->isCompetitionPubliclyVisible->check($competitionId) === false) {
                return null;
            }

            $this->secretPuzzleAccess->assertWritableByViewer($puzzleId);
            $puzzlingType = PuzzlingType::fromPuzzlersCount(max(1, $puzzlersCount));
            $event = null;

            // The official entry's round - only a round of the form's own event (one-time event or edition)
            if ($namedRoundId !== null && $competitionId !== null && Uuid::isValid($namedRoundId)) {
                // Fail-soft: a named round that does not answer leaves the form's pick to decide
                try {
                    $named = $this->unfinishedResultRound->forNewResult(
                        roundId: $namedRoundId,
                        competitionId: null,
                        seriesId: null,
                        puzzleId: $puzzleId,
                        puzzlingType: $puzzlingType,
                        solveDay: $solveDay ?? $this->clock->now(),
                    );
                    $event = $named->roundChosen && $named->competition->id->toString() === strtolower($competitionId) ? $named : null;
                } catch (UnfinishedResultNeedsTimeLimit | CompetitionRoundNotFound) {
                    $event = null;
                }
            }

            $event ??= $this->unfinishedResultRound->forNewResult(
                roundId: null,
                competitionId: $competitionId,
                seriesId: $pick->seriesId(),
                puzzleId: $puzzleId,
                puzzlingType: $puzzlingType,
                solveDay: $solveDay ?? $this->clock->now(),
            );
        } catch (UnfinishedResultRoundNotIdentified) {
            return $competitionId !== null
                ? $this->sharedByRoundsWithOneLimit($competitionId, $puzzleId, PuzzlingType::fromPuzzlersCount(max(1, $puzzlersCount)))
                : null;
        } catch (UnfinishedResultNeedsTimeLimit | CompetitionRoundNotFound | PuzzleNotFound | PuzzleNotRevealedYet) {
            return null;
        }

        if ($this->roundStillHides($event->round->id->toString(), $puzzleId)) {
            return null;
        }

        return new UnfinishedRoundHint(
            name: $event->round->name,
            minutesLimit: $event->minutesLimit(),
            category: $event->round->category->value,
        );
    }

    /**
     * The puzzle is in several rounds of the category and nothing names one (A8): offered only when every one of them
     * has the same limit and none still hides the puzzle entirely - the hint names them all.
     */
    private function sharedByRoundsWithOneLimit(string $competitionId, string $puzzleId, PuzzlingType $puzzlingType): null|UnfinishedRoundHint
    {
        $hidden = RoundPuzzleReveal::sqlHidden('crp', 'cr');
        $entirely = PuzzleHideMode::Entirely->value;

        /** @var list<array{name: string, minutes_limit: null|int|string, hides: bool}> $rounds */
        $rounds = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cr.name, cr.minutes_limit, (COALESCE(crp.hide_mode, '{$entirely}') = '{$entirely}' AND {$hidden}) AS hides
FROM competition_round cr
INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id AND crp.puzzle_id = :puzzleId
WHERE cr.competition_id = :competitionId
    AND cr.category = :category
ORDER BY cr.starts_at, cr.name
SQL,
            [
                'competitionId' => $competitionId,
                'puzzleId' => $puzzleId,
                'category' => $puzzlingType->value,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        $limits = array_values(array_unique(array_map(static fn (array $round): null|int => $round['minutes_limit'] !== null ? (int) $round['minutes_limit'] : null, $rounds)));
        $limit = count($limits) === 1 ? $limits[0] ?? null : null;

        if (count($rounds) < 2 || $limit === null || array_any($rounds, static fn (array $round): bool => $round['hides'])) {
            return null;
        }

        return new UnfinishedRoundHint(
            name: implode(' / ', array_map(static fn (array $round): string => $round['name'], $rounds)),
            minutesLimit: $limit,
            category: $puzzlingType->value,
        );
    }

    private function roundStillHides(string $roundId, string $puzzleId): bool
    {
        $hidden = RoundPuzzleReveal::sqlHidden('crp', 'cr');
        $entirely = PuzzleHideMode::Entirely->value;

        return $this->database->fetchOne(
            <<<SQL
SELECT 1
FROM competition_round_puzzle crp
INNER JOIN competition_round cr ON cr.id = crp.round_id
WHERE crp.round_id = :roundId
    AND crp.puzzle_id = :puzzleId
    AND COALESCE(crp.hide_mode, '{$entirely}') = '{$entirely}'
    AND {$hidden}
SQL,
            [
                'roundId' => $roundId,
                'puzzleId' => $puzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        ) !== false;
    }
}
