<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleImageNotRestorable;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RecalculateKarma;
use SpeedPuzzling\Web\Message\RestorePuzzleImage;
use SpeedPuzzling\Web\Query\GetPuzzleHistory;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Results\PuzzleHistoryEntry;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\OutdatedPuzzleRequests;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\PuzzleRecordUpdater;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * "Restore this picture" (docs/features/puzzle-approvals.md "Restoring a picture"): the picture an image change of the
 * puzzle's history replaced comes back as a moderator's direct edit - saved by PuzzleRecordUpdater (every other field
 * as the puzzle has it now) and logged as `puzzle_edited` with `details.restoredFromDecisionId`, which the history
 * shows as "Picture restored".
 *
 * What is restored is what the history shows for that line (GetPuzzleHistory - PuzzleHistoryEntry::$previousImage),
 * copied from its originals archive copy when the approval kept one, else from the old object itself, under a NEW name
 * (PuzzleImageStorage::useCopyOf()): the image caches key on the name, and the new fingerprint runs the analyses again.
 * The object it is copied from stays. Refused (PuzzleImageNotRestorable), with nothing changed, when neither exists any
 * more or the puzzle's picture is hidden now.
 */
#[AsMessageHandler]
readonly final class RestorePuzzleImageHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private GetPuzzleHistory $getPuzzleHistory,
        private PuzzleRecordUpdater $puzzleRecordUpdater,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private OutdatedPuzzleRequests $outdatedPuzzleRequests,
        private Filesystem $filesystem,
        private ImageOptimizer $imageOptimizer,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws PuzzleImageNotRestorable
     * @throws PuzzleIsStillSecret
     * @throws InvalidPuzzleValues
     */
    public function __invoke(RestorePuzzleImage $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $moderator = $this->playerRepository->get($message->moderatorId);

        // A hidden picture's file name must not be guessable - PuzzleImageStorage would build one from the public record
        if ($puzzle->isImageHiddenAt($this->clock->now())) {
            throw PuzzleImageNotRestorable::pictureHidden();
        }

        $entry = $this->historyEntry($puzzle->id->toString(), $message->decisionId);
        $previousImage = $entry->previousImage;
        assert($previousImage !== null);

        if ($previousImage === $puzzle->image) {
            throw PuzzleImageNotRestorable::alreadyThePicture();
        }

        $source = $this->storedCopy($entry) ?? throw PuzzleImageNotRestorable::pictureGone($previousImage);

        // Validates every value before it changes anything; only an admin corrects a secret competition puzzle
        $change = $this->puzzleRecordUpdater->update(
            $puzzle,
            new PuzzleRecordValues(
                name: $puzzle->name,
                nameLanguage: $puzzle->nameLanguage,
                alternativeNames: $puzzle->alternativeNames(),
                manufacturerId: $puzzle->manufacturer?->id->toString(),
                piecesCount: $puzzle->piecesCount,
                eans: $puzzle->eans(),
                brandCodes: $puzzle->brandCodes(),
                image: PuzzleImageChoice::Restored,
                restoredImage: $source,
                restoredImageRatio: $this->ratio($source),
            ),
            allowSecret: $moderator->isAdmin,
        );

        // A pending proposal of the puzzle may ask for nothing more than this
        $this->outdatedPuzzleRequests->afterRecordChange($puzzle);

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::PuzzleEdited,
            decidedBy: $moderator,
            puzzleId: $puzzle->id,
            puzzleName: $puzzle->name,
            details: [
                'image' => PuzzleImageChoice::Restored->value,
                // The history line it went back from, and the stored object the new picture was copied from
                'restoredFromDecisionId' => $entry->decisionId,
                'restoredFrom' => $source,
            ] + $change,
        );

        // Karma (docs/features/karma.md): a direct edit of the moderator
        $this->messageBus->dispatch(RecalculateKarma::forPlayers($moderator));
    }

    /**
     * @throws PuzzleImageNotRestorable
     */
    private function historyEntry(string $puzzleId, string $decisionId): PuzzleHistoryEntry
    {
        foreach ($this->getPuzzleHistory->forPuzzle($puzzleId) as $entry) {
            if ($entry->decisionId !== null && strtolower($entry->decisionId) === strtolower($decisionId) && $entry->previousImage !== null) {
                return $entry;
            }
        }

        throw PuzzleImageNotRestorable::nothingToRestore();
    }

    /**
     * The archive copy first (nothing renames or deletes it), else the old object itself.
     */
    private function storedCopy(PuzzleHistoryEntry $entry): null|string
    {
        foreach ([$entry->previousImageArchivePath, $entry->previousImage] as $candidate) {
            if ($candidate !== null && $this->filesystem->fileExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Width / height of the stored picture - null when it cannot be read; the page then shows it without a known ratio.
     */
    private function ratio(string $storedImage): null|float
    {
        try {
            return $this->imageOptimizer->getImageRatioFromBlob($this->filesystem->read($storedImage));
        } catch (Throwable) {
            return null;
        }
    }
}
