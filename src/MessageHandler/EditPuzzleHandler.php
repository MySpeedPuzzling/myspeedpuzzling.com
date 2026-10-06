<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\PuzzleRecordUpdater;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A moderator's direct edit of a puzzle (docs/features/puzzle-approvals.md). Saved the same way as an
 * approved change request and recorded in the decision log with the puzzle before and after - an edit
 * that changes nothing records nothing.
 */
#[AsMessageHandler]
readonly final class EditPuzzleHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private PuzzleRecordUpdater $puzzleRecordUpdater,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerNotFound
     * @throws InvalidPuzzleValues
     */
    public function __invoke(EditPuzzle $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $editor = $this->playerRepository->get($message->editorId);

        // Validates every value before it changes anything
        // Only an admin corrects a secret competition puzzle before its reveal
        $change = $this->puzzleRecordUpdater->update($puzzle, $message->values, allowSecret: $editor->isAdmin);

        if ($change['before'] === $change['after']) {
            return;
        }

        $note = $message->note !== null ? trim($message->note) : '';

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::PuzzleEdited,
            decidedBy: $editor,
            puzzleId: $puzzle->id,
            puzzleName: $puzzle->name,
            note: $note !== '' ? $note : null,
            details: ['image' => $message->values->image->value] + $change,
        );
    }
}
