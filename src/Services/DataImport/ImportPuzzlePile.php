<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportPileAnswer;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportPuzzlePile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Message\RecordImportPuzzlePileAnswer;
use SpeedPuzzling\Web\Message\StartImportPuzzlePileAnswer;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Repository\DataImportPileAnswerRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\DataImportStatus;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One answer for a whole pile of an import's puzzles (docs/features/data-import/guided-flow.md §6.2, §6.3): "Keep
 * them just for me" or "Don't import them" for every puzzle that is not in our database, "Keep the rest just for me"
 * for the puzzles the player would have to pick one by one. **Neither answer matches a puzzle with the database** -
 * nothing of it reaches a leaderboard, and each puzzle can be decided otherwise afterwards.
 *
 * What makes it safe:
 *
 * - **an answer is a row** (DataImportPileAnswer), written under the import's lock (StartImportPuzzlePileAnswer):
 *   one answer at a time per import and pile - a second is told "already being answered" and nothing of it is
 *   applied; nothing is started while puzzles are still looked up or when the import is not as the screen showed it;
 * - **the puzzles are worked out here, never sent by a form**: the pile's puzzles of that import of that player
 *   that wait, each with the version it has now (GetImportPuzzleScreen::waitingOfPile()) - and only those that
 *   **were in the pile when the answer was given**: a puzzle built or looked up after that moment is not answered;
 * - every turn first asks whether the answer still runs and whether the import still stands as it did when the
 *   answer was given (`revision`: a sheet read again, the puzzles looked up again) - if not, the answer ends there;
 * - the puzzles are decided through the one message every decision goes through (DecideImportPuzzles - under the
 *   import's lock, version-checked, all of a chunk or none), CHUNK at a time, by a cursor that only moves forward;
 * - a puzzle decided between the read and the lock (another tab) is **skipped and counted**: the chunk is sent again
 *   without it - what the player decided themselves is never overwritten.
 *
 * Up to AT_ONCE puzzles are answered in the request; a larger pile goes to the `imports` worker
 * (AnswerImportPuzzlePile), a chunk a turn, and the pile's page follows it. An answer without a sign of life for a
 * quarter of an hour is ended by the sweeper (ContinueImportBatchesHandler): what it decided stays, the rest waits.
 */
readonly final class ImportPuzzlePile
{
    public const int CHUNK = 200;
    public const int AT_ONCE = 200;

    public const string STARTED = 'started';
    public const string DONE = 'done';
    public const string QUEUED = 'queued';
    public const string BUSY = 'busy';
    public const string STILL_LOOKING = 'still_looking';
    public const string CHANGED = 'changed';
    public const string NOTHING = 'nothing';
    public const string REFUSED = 'refused';

    /** What one answer may be, per pile: the puzzles to pick one by one are only ever kept - never left out in bulk */
    public const array CHOICES = [
        'not-found' => [ImportPuzzleChoice::Unlisted, ImportPuzzleChoice::LeftOut],
        'choose' => [ImportPuzzleChoice::Unlisted],
    ];

    public function __construct(
        private GetImportPuzzleScreen $getImportPuzzleScreen,
        private GetDataImports $getDataImports,
        private DataImportPileAnswerRepository $answerRepository,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string $shownRevision the import as the screen that asks showed it (DataImportProgress::puzzlesRevision())
     *
     * @return array{state: string, decided: int, skipped: int, waiting: int}
     *
     * @throws DataImportNotFound another player's import, or none
     */
    public function answer(string $importId, string $playerId, string $pile, string $choice, string $shownRevision): array
    {
        $answerId = Uuid::uuid7()->toString();

        try {
            /** @var array{state: string, waiting: int} $started */
            $started = $this->messageBus->dispatch(new StartImportPuzzlePileAnswer($answerId, $importId, $playerId, $pile, $choice, $shownRevision))
                ->last(HandledStamp::class)
                ?->getResult();
        } catch (HandlerFailedException $exception) {
            throw $exception->getPrevious() instanceof DataImportNotFound ? $exception->getPrevious() : $exception;
        }

        if ($started['state'] !== self::STARTED) {
            return ['state' => $started['state'], 'decided' => 0, 'skipped' => 0, 'waiting' => $started['waiting']];
        }

        if ($started['waiting'] > self::AT_ONCE) {
            $this->messageBus->dispatch(new AnswerImportPuzzlePile($answerId));

            return ['state' => self::QUEUED, 'decided' => 0, 'skipped' => 0, 'waiting' => $started['waiting']];
        }

        // Bounded: the pile held AT_ONCE puzzles at most, and the cursor only moves forward
        for ($turn = 0; $turn <= (int) ceil(self::AT_ONCE / self::CHUNK) && $this->turn($answerId); $turn++) {
            continue;
        }

        $this->entityManager->clear();
        $answer = $this->answerRepository->find($answerId);

        if ($answer !== null && $answer->isOpen()) {
            // Never left open by a request: whatever is left is the worker's
            $this->messageBus->dispatch(new AnswerImportPuzzlePile($answerId));

            return ['state' => self::QUEUED, 'decided' => $answer->puzzlesDecided, 'skipped' => $answer->puzzlesSkipped, 'waiting' => $started['waiting']];
        }

        return [
            'state' => $answer !== null && $answer->endedAs === DataImportPileAnswer::CHANGED && $answer->puzzlesDecided === 0 ? self::CHANGED : self::DONE,
            'decided' => $answer === null ? 0 : $answer->puzzlesDecided,
            'skipped' => $answer === null ? 0 : $answer->puzzlesSkipped,
            'waiting' => 0,
        ];
    }

    /**
     * One turn of an answer: the next CHUNK puzzles of its pile take it.
     *
     * @return bool whether another turn follows - false: the answer has ended (or was not running)
     */
    public function turn(string $answerId): bool
    {
        $this->entityManager->clear();
        $answer = $this->answerRepository->find($answerId);

        if ($answer === null || $answer->isOpen() === false) {
            return false;
        }

        $importId = $answer->import->id->toString();
        $playerId = $answer->import->player->id->toString();
        $pile = $answer->pile;
        $choice = ImportPuzzleChoice::tryFrom($answer->choice);
        $after = $answer->lastPuzzleId?->toString();
        $progress = $this->getDataImports->progress($importId);

        // The import is not what the answer was given for any more: its sheets were read again, its puzzles looked up
        // again, it was finished or discarded - nothing more is answered
        if (
            $choice === null
            || in_array($choice, self::CHOICES[$pile] ?? [], true) === false
            || $progress === null
            || $progress->status !== DataImportStatus::SettingUp->value
            || $progress->sheetsWorking > 0
            || $progress->pairing['running']
            || $progress->pairing['searching'] > 0
            || hash_equals($answer->revision, $progress->puzzlesRevision()) === false
        ) {
            $this->messageBus->dispatch(new RecordImportPuzzlePileAnswer($answerId, endedAs: DataImportPileAnswer::CHANGED));

            return false;
        }

        // Only what was in the pile when the answer was given - the set the player saw
        $waiting = $this->getImportPuzzleScreen->waitingOfPile($importId, $playerId, $pile, self::CHUNK, $after, $answer->startedAt);

        if ($waiting === []) {
            $this->messageBus->dispatch(new RecordImportPuzzlePileAnswer($answerId, endedAs: DataImportPileAnswer::DONE));

            return false;
        }

        $read = count($waiting);
        $last = $waiting[array_key_last($waiting)]['id'];
        $skipped = 0;

        // A puzzle decided between the read and the lock refuses the whole message: it is sent again without the
        // puzzles that changed. Each try names fewer puzzles, so it ends
        while ($waiting !== []) {
            try {
                $this->messageBus->dispatch(new DecideImportPuzzles($importId, $playerId, array_map(
                    static fn (array $puzzle): ImportPuzzleDecisionChange => new ImportPuzzleDecisionChange($puzzle['id'], $choice, null, $puzzle['version']),
                    $waiting,
                )));

                break;
            } catch (HandlerFailedException $exception) {
                $cause = $exception->getPrevious();
                $this->entityManager->clear();

                if ($cause instanceof DataImportNotFound) {
                    $this->messageBus->dispatch(new RecordImportPuzzlePileAnswer($answerId, endedAs: DataImportPileAnswer::CHANGED));

                    return false;
                }

                if ($cause instanceof ImportPuzzleDecidedMeanwhile === false || $cause->importPuzzleIds === []) {
                    throw $exception;
                }

                $changed = array_map(strtolower(...), $cause->importPuzzleIds);
                $left = array_values(array_filter($waiting, static fn (array $puzzle): bool => in_array(strtolower($puzzle['id']), $changed, true) === false));

                if (count($left) === count($waiting)) {
                    throw $exception;
                }

                // The refused message's puzzles were read afresh above (the entity manager is cleared): the next try
                // reads them - and their versions - again
                $skipped += count($waiting) - count($left);
                $waiting = $left;
            }
        }

        $more = $read === self::CHUNK;
        $this->messageBus->dispatch(new RecordImportPuzzlePileAnswer($answerId, count($waiting), $skipped, $last, $more ? null : DataImportPileAnswer::DONE));

        return $more;
    }
}
