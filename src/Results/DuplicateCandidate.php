<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Two results of one person with the same puzzle and the same time - what DuplicateClassifier judges
 * (docs/features/duplicate-results.md, "Definitions").
 */
readonly final class DuplicateCandidate
{
    public const string DIFFERENCE_FINISHED_AT = 'finished_at';
    public const string DIFFERENCE_GROUP = 'group';
    public const string DIFFERENCE_COMPETITION = 'competition';
    public const string DIFFERENCE_FIRST_TRY = 'first_try';
    public const string DIFFERENCE_UNBOXED = 'unboxed';
    public const string DIFFERENCE_COMMENT = 'comment';
    public const string DIFFERENCE_PHOTO = 'photo';

    public function __construct(
        // The person both results belong to - their solo result, or a pair/team they are a registered member of
        public string $personId,
        public string $puzzleId,
        public string $puzzleName,
        public int $secondsToSolve,
        // Saved first
        public DuplicateCandidateTime $older,
        public DuplicateCandidateTime $newer,
        // The person has another result of the puzzle on either day, with a different time (or none)
        public bool $practiceSession,
        // The tracker saved anything else between the two copies; null = not checked (different trackers,
        // or saved more than an hour apart - it decides nothing there)
        public null|bool $savedInBetween,
    ) {
    }

    public function sameTracker(): bool
    {
        return $this->older->trackerId === $this->newer->trackerId;
    }

    public function sameDay(): bool
    {
        return $this->older->solvedDay === $this->newer->solvedDay;
    }

    /**
     * How long after the first copy the second one was saved.
     */
    public function gapSeconds(): int
    {
        return abs($this->newer->trackedAt->getTimestamp() - $this->older->trackedAt->getTimestamp());
    }

    /**
     * Which of the fields compared by "identical in every field" differ between the copies.
     *
     * @return list<string>
     */
    public function differences(): array
    {
        $older = $this->older;
        $newer = $this->newer;
        $differences = [];

        if ($older->finishedAt?->format('Y-m-d H:i:s') !== $newer->finishedAt?->format('Y-m-d H:i:s')) {
            $differences[] = self::DIFFERENCE_FINISHED_AT;
        }

        if ($older->teamId !== $newer->teamId) {
            $differences[] = self::DIFFERENCE_GROUP;
        }

        if ($older->competitionId !== $newer->competitionId || $older->competitionRoundId !== $newer->competitionRoundId) {
            $differences[] = self::DIFFERENCE_COMPETITION;
        }

        if ($older->firstAttempt !== $newer->firstAttempt) {
            $differences[] = self::DIFFERENCE_FIRST_TRY;
        }

        if ($older->unboxed !== $newer->unboxed) {
            $differences[] = self::DIFFERENCE_UNBOXED;
        }

        if (self::normalizedComment($older->comment) !== self::normalizedComment($newer->comment)) {
            $differences[] = self::DIFFERENCE_COMMENT;
        }

        if ($older->hasPhoto !== $newer->hasPhoto) {
            $differences[] = self::DIFFERENCE_PHOTO;
        }

        return $differences;
    }

    /**
     * The registered people of either copy besides the person themselves.
     *
     * @return list<array{id: string, name: null|string, code: string}>
     */
    public function otherPeople(): array
    {
        $others = [];

        foreach ([...$this->older->people, ...$this->newer->people] as $person) {
            if ($person['id'] !== $this->personId) {
                $others[$person['id']] = $person;
            }
        }

        return array_values($others);
    }

    /**
     * What a stored case keeps of the pair - it outlives the results it points at.
     *
     * @return array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     seconds: int,
     *     day_a: string,
     *     day_b: string,
     *     tracked_at_a: string,
     *     tracked_at_b: string,
     *     gap_seconds: int,
     *     differences: list<string>,
     *     tracker_a: array{id: string, name: null|string, code: string},
     *     tracker_b: array{id: string, name: null|string, code: string},
     *     others: list<array{id: string, name: null|string, code: string}>,
     * }
     */
    public function snapshot(): array
    {
        return [
            'puzzle_id' => $this->puzzleId,
            'puzzle_name' => $this->puzzleName,
            'seconds' => $this->secondsToSolve,
            'day_a' => $this->older->solvedDay,
            'day_b' => $this->newer->solvedDay,
            'tracked_at_a' => $this->older->trackedAt->format('Y-m-d H:i:s'),
            'tracked_at_b' => $this->newer->trackedAt->format('Y-m-d H:i:s'),
            'gap_seconds' => $this->gapSeconds(),
            'differences' => $this->differences(),
            'tracker_a' => ['id' => $this->older->trackerId, 'name' => $this->older->trackerName, 'code' => $this->older->trackerCode],
            'tracker_b' => ['id' => $this->newer->trackerId, 'name' => $this->newer->trackerName, 'code' => $this->newer->trackerCode],
            'others' => $this->otherPeople(),
        ];
    }

    private static function normalizedComment(null|string $comment): null|string
    {
        $comment = trim($comment ?? '');

        return $comment === '' ? null : $comment;
    }
}
