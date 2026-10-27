<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FinishedPhotoAnalysis;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecordFinishedPhotoAnalyses;
use SpeedPuzzling\Web\Repository\FinishedPhotoAnalysisRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisOutcome;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every item of a batch, in one transaction (docs/features/insights/finished-photos.md): the puzzles, their results
 * carrying a photo of a public player and those results' stored analyses are read in three statements. An item is
 * stored only for a puzzle whose image is shown (else `not_found`), only while the puzzle's catalogue picture is the
 * one the photo was matched against and the photo is still one of the puzzle's (else `stale_fingerprint`) - it then
 * replaces the photo's stored analysis of that analyzer whole. Checks before it changes anything. The same photo and
 * analyzer twice in one batch: the later item replaces the earlier one.
 */
#[AsMessageHandler]
readonly final class RecordFinishedPhotoAnalysesHandler
{
    public const string NOT_FOUND_MESSAGE = 'No such puzzle with an image to analyse.';

    public const string PHOTO_GONE_MESSAGE = 'The photo is not one of the puzzle\'s finished photos any more - nothing was stored.';

    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private FinishedPhotoAnalysisRepository $finishedPhotoAnalysisRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<int, PuzzleImageAnalysisOutcome> keyed like the message's analyses
     */
    public function __invoke(RecordFinishedPhotoAnalyses $message): array
    {
        $now = $this->clock->now();
        $puzzleIds = array_values(array_unique(array_map(
            static fn ($input): string => $input->puzzleId,
            $message->analyses,
        )));
        $puzzles = $this->puzzleRepository->findByIds($puzzleIds);
        $times = $this->puzzleSolvingTimeRepository->findWithFinishedPhotoOfPuzzles(array_keys($puzzles));

        /** @var array<string, PuzzleSolvingTime> $photos puzzle id | photo fingerprint => the result carrying it */
        $photos = [];

        foreach ($times as $time) {
            assert($time->finishedPuzzlePhoto !== null);
            $photos[self::key(strtolower($time->puzzle->id->toString()), PuzzleImageFingerprint::of($time->finishedPuzzlePhoto))] = $time;
        }

        /** @var array<string, FinishedPhotoAnalysis> $stored solving time id | analyzer => its analysis */
        $stored = [];

        foreach ($this->finishedPhotoAnalysisRepository->findForSolvingTimes($times) as $analysis) {
            $stored[self::key($analysis->solvingTime->id->toString(), $analysis->analyzer->value)] = $analysis;
        }

        $outcomes = [];

        foreach ($message->analyses as $index => $input) {
            $puzzle = $puzzles[$input->puzzleId] ?? null;

            if (!$puzzle instanceof Puzzle || $puzzle->isImageHiddenAt($now)) {
                $outcomes[$index] = PuzzleImageAnalysisOutcome::notFound(self::NOT_FOUND_MESSAGE);
                continue;
            }

            $currentFingerprint = $puzzle->image !== null ? PuzzleImageFingerprint::of($puzzle->image) : null;

            if ($currentFingerprint !== $input->imageFingerprint) {
                $outcomes[$index] = PuzzleImageAnalysisOutcome::staleFingerprint(
                    $currentFingerprint === null
                        ? 'The puzzle has no image any more - nothing was stored.'
                        : sprintf('The puzzle\'s image changed since the photo was matched against it (its current imageFingerprint is "%s") - nothing was stored.', $currentFingerprint),
                    $currentFingerprint,
                );
                continue;
            }

            $time = $photos[self::key($input->puzzleId, $input->photoFingerprint)] ?? null;

            if ($time === null) {
                $outcomes[$index] = PuzzleImageAnalysisOutcome::staleFingerprint(self::PHOTO_GONE_MESSAGE, $currentFingerprint);
                continue;
            }

            $key = self::key($time->id->toString(), $input->analyzer->value);
            $analysis = $stored[$key] ?? null;

            if ($analysis !== null) {
                $analysis->replace(
                    analyzerVersion: $input->analyzerVersion,
                    photoFingerprint: $input->photoFingerprint,
                    imageFingerprint: $input->imageFingerprint,
                    result: $input->result,
                    analyzedAt: $input->analyzedAt,
                    receivedAt: $now,
                );
            } else {
                $analysis = new FinishedPhotoAnalysis(
                    id: Uuid::uuid7(),
                    solvingTime: $time,
                    analyzer: $input->analyzer,
                    analyzerVersion: $input->analyzerVersion,
                    photoFingerprint: $input->photoFingerprint,
                    imageFingerprint: $input->imageFingerprint,
                    result: $input->result,
                    analyzedAt: $input->analyzedAt,
                    receivedAt: $now,
                );
                $this->finishedPhotoAnalysisRepository->save($analysis);
                $stored[$key] = $analysis;
            }

            $outcomes[$index] = PuzzleImageAnalysisOutcome::stored();
        }

        return $outcomes;
    }

    private static function key(string $id, string $other): string
    {
        return $id . '|' . $other;
    }
}
