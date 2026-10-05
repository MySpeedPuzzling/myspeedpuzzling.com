<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\LanguageTag;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SubmitPuzzleMergeRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SubmitPuzzleMergeRequest $message): void
    {
        $sourcePuzzle = $this->puzzleRepository->get($message->sourcePuzzleId);
        $reporter = $this->playerRepository->get($message->reporterId);
        $now = $this->clock->now();

        // Validate all duplicate puzzle IDs exist
        foreach ($message->duplicatePuzzleIds as $puzzleId) {
            $this->puzzleRepository->get($puzzleId);
        }

        // Ensure source puzzle is included in the list
        $allPuzzleIds = array_unique(array_merge(
            [$message->sourcePuzzleId],
            $message->duplicatePuzzleIds,
        ));

        // Validate that there's at least one actual duplicate (not just source itself)
        if (count($allPuzzleIds) < 2) {
            throw new \InvalidArgumentException('At least one duplicate puzzle different from the source is required.');
        }

        $mergeRequest = new PuzzleMergeRequest(
            id: Uuid::fromString($message->mergeRequestId),
            sourcePuzzle: $sourcePuzzle,
            reporter: $reporter,
            submittedAt: $now,
            reportedDuplicatePuzzleIds: $allPuzzleIds,
            reportedNameLanguages: self::reportedNameLanguages($message->reportedNameLanguages, $allPuzzleIds),
        );

        $this->entityManager->persist($mergeRequest);
    }

    /**
     * Base languages of the reported puzzles only, keyed by the lower-case id - a language of a puzzle outside the
     * request, or no language at all, says nothing.
     *
     * @param array<string, string> $reportedNameLanguages
     * @param array<string> $puzzleIds
     *
     * @return array<string, string>
     */
    private static function reportedNameLanguages(array $reportedNameLanguages, array $puzzleIds): array
    {
        $puzzleIds = array_map(strtolower(...), $puzzleIds);
        $languages = [];

        foreach ($reportedNameLanguages as $puzzleId => $language) {
            $puzzleId = strtolower($puzzleId);
            $tag = LanguageTag::normalize($language);

            if ($tag !== null && in_array($puzzleId, $puzzleIds, true)) {
                $languages[$puzzleId] = LanguageTag::base($tag);
            }
        }

        return $languages;
    }
}
