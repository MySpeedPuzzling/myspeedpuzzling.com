<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SeriesEditions;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Query\SeriesEditionMatch;
use SpeedPuzzling\Web\Results\SeriesEditionResolution;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SeriesEditionMatchKind;

/**
 * The edition of one series pick, by the one rule (SeriesEditionMatch, docs/features/events-page/
 * high-frequency-series.md) - one statement. The time's own writes call it before persist, once the time is final
 * (puzzle, group, day): AddPuzzleSolvingTimeHandler, EditPuzzleSolvingTimeHandler, UndoAutoRemovalHandler - always a
 * fresh answer. The form's preview asks the same with its inputs. Everything else is reconciled in bulk
 * (SeriesEditionReconciler).
 */
readonly final class SeriesEditionResolver
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Not identified for a time without a series pick
     */
    public function resolve(PuzzleSolvingTime $time): SeriesEditionResolution
    {
        if ($time->competitionSeries === null) {
            return SeriesEditionResolution::notIdentified();
        }

        return $this->answer(
            $time->competitionSeries->id->toString(),
            $time->puzzle->id->toString(),
            $time->finishedAt ?? $time->trackedAt,
            $time->puzzlingType,
        );
    }

    /**
     * What a save would match - the add-time form's preview. A puzzle that is no uuid counts as none (only the date can
     * match then).
     */
    public function preview(string $seriesId, null|string $puzzleId, DateTimeImmutable $solveDay, PuzzlingType $category): SeriesEditionResolution
    {
        if (Uuid::isValid($seriesId) === false) {
            return SeriesEditionResolution::notIdentified();
        }

        return $this->answer($seriesId, $puzzleId !== null && Uuid::isValid($puzzleId) ? $puzzleId : null, $solveDay, $category);
    }

    private function answer(string $seriesId, null|string $puzzleId, DateTimeImmutable $solveDay, PuzzlingType $category): SeriesEditionResolution
    {
        $candidates = SeriesEditionMatch::sqlCandidates('c.series_id = CAST(:seriesId AS UUID)');
        $answer = SeriesEditionMatch::sqlAnswer(
            'CAST(:seriesId AS UUID)',
            'CAST(:puzzleId AS UUID)',
            'CAST(:category AS VARCHAR)',
            'CAST(:day AS DATE)',
        );

        $row = $this->database->fetchAssociative(
            <<<SQL
WITH {$candidates}
SELECT a.competition_id, a.kind
FROM ({$answer}) a
SQL,
            [
                'seriesId' => $seriesId,
                'puzzleId' => $puzzleId,
                'category' => $category->value,
                // The same day SQL reads from COALESCE(finished_at, tracked_at) of a stored time
                'day' => $solveDay->format('Y-m-d'),
                SeriesEditionMatch::NOW_PARAMETER => $this->clock->now()->format(SeriesEditionMatch::DATE_FORMAT),
            ],
        );

        /** @var false|array{competition_id: null|string, kind: null|string} $row */
        if ($row === false || $row['competition_id'] === null || $row['kind'] === null) {
            return SeriesEditionResolution::notIdentified();
        }

        return new SeriesEditionResolution($row['competition_id'], SeriesEditionMatchKind::from($row['kind']));
    }
}
