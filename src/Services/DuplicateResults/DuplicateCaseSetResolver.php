<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\ResultDuplicateCase;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseNotFound;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicateCaseRepository;

/**
 * The set an action on the review page is about, found again from one of its cases exactly the way the page built
 * it (GetPlayerDuplicateCases): the person's open cases linked through shared results, only cases with both
 * copies still there (docs/features/duplicate-results.md, "Review page").
 */
readonly final class DuplicateCaseSetResolver
{
    public function __construct(
        private ResultDuplicateCaseRepository $caseRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
    ) {
    }

    /**
     * @param list<string> $shownTimeIds the copies the page showed - empty = the set as it is now
     * @return array{cases: non-empty-list<ResultDuplicateCase>, copies: array<string, PuzzleSolvingTime>} the
     *         person's open cases of the set and its copies by id
     *
     * @throws DuplicateCaseNotFound the case is not the player's
     * @throws DuplicateCaseChanged decided meanwhile, a copy is gone, or the set is not the one the page showed
     */
    public function resolve(string $caseId, string $playerId, array $shownTimeIds): array
    {
        $case = $this->caseRepository->get($caseId);

        if ($case->player->id->toString() !== strtolower($playerId)) {
            throw new DuplicateCaseNotFound();
        }

        if ($case->isOpen() === false) {
            throw new DuplicateCaseChanged();
        }

        $reachable = $this->setOf($case, $this->caseRepository->findOpenOf($case->player));
        $copies = [];

        foreach (DuplicateSets::timeIdsOf(self::pairs($reachable)) as $timeId) {
            $time = $this->puzzleSolvingTimeRepository->findById(Uuid::fromString($timeId));

            if ($time !== null) {
                $copies[$timeId] = $time;
            }
        }

        // Without a copy the case is not on the page any more - and without it the set may fall apart
        $withBothCopies = array_values(array_filter(
            $reachable,
            static fn (ResultDuplicateCase $other): bool => isset($copies[$other->timeAId->toString()], $copies[$other->timeBId->toString()]),
        ));

        if (in_array($case, $withBothCopies, true) === false) {
            throw new DuplicateCaseChanged();
        }

        $cases = $this->setOf($case, $withBothCopies);
        $setTimeIds = DuplicateSets::timeIdsOf(self::pairs($cases));
        $copies = array_intersect_key($copies, array_flip($setTimeIds));

        if ($shownTimeIds !== []) {
            $shown = array_values(array_unique(array_map('strtolower', $shownTimeIds)));
            sort($shown);
            sort($setTimeIds);

            // A copy added or decided since the page was rendered - never act on copies the player did not see
            if ($shown !== $setTimeIds) {
                throw new DuplicateCaseChanged();
            }
        }

        return ['cases' => $cases, 'copies' => $copies];
    }

    /**
     * @param list<ResultDuplicateCase> $cases
     * @return non-empty-list<ResultDuplicateCase>
     */
    private function setOf(ResultDuplicateCase $case, array $cases): array
    {
        $position = array_search($case, $cases, true);

        if ($position === false) {
            return [$case];
        }

        foreach (DuplicateSets::group(self::pairs($cases)) as $keys) {
            if (in_array($position, $keys, true)) {
                return array_map(static fn (int $key): ResultDuplicateCase => $cases[$key], $keys);
            }
        }

        return [$case];
    }

    /**
     * @param list<ResultDuplicateCase> $cases
     * @return list<array{string, string}>
     */
    private static function pairs(array $cases): array
    {
        return array_map(
            static fn (ResultDuplicateCase $case): array => [$case->timeAId->toString(), $case->timeBId->toString()],
            $cases,
        );
    }
}
