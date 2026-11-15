<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportGroupAnswer;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\AnswerImportRowsGroup;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A group answer is asked for (docs/features/data-import/group-answers.md). Under the import's lock, everything
 * checked before the answer is written down:
 *
 * 1. the import is the player's and rows can be added from it (else "not found");
 * 2. the same request id as before answers that group answer - nothing is started twice;
 * 3. only the groups one answer is right for: an unusual time ("these times are right" / "leave them out") and
 *    "the file says it is on MySpeedPuzzling" ("they are missing" / "leave them out"). Rows that may be a duplicate
 *    of a result that exists (`similar_result_exists`, `somebody_elses_result`) are never answered as a group -
 *    anything else is "not found": a page never sends it;
 * 4. one answer at a time per reason: while another answer goes through the rows of this reason, that one answers
 *    (`busy`) - "add all" and "leave them out" side by side would split the group;
 * 5. the group is counted; an empty group answers null.
 *
 * Nothing is confirmed here: the answer is gone through by ProcessImportGroupAnswer - at once when it is small
 * (`atOnce`), on the `imports` queue otherwise.
 */
#[AsMessageHandler]
readonly final class AnswerImportRowsGroupHandler
{
    /** Groups one answer is right for */
    public const array CODES = [ImportRowRefusal::UnusualTime->value, ImportRowRefusal::SaidAlreadyOnProfile->value];

    /** Up to so many rows are answered in the request; more go to the worker, with the live line */
    public const int AT_ONCE = 200;

    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportRowRepository $rowRepository,
        private DataImportBatchRepository $batchRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return null|array{id: string, atOnce: bool, started: bool, busy: bool}
     *
     * @throws DataImportNotFound
     */
    public function __invoke(AnswerImportRowsGroup $message): null|array
    {
        if (Uuid::isValid($message->requestId) === false || Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);
        $same = $this->batchRepository->findGroupAnswer($message->requestId);

        if ($same !== null) {
            if ($same->import->id->equals($import->id) === false) {
                throw new DataImportNotFound();
            }

            return ['id' => $same->id->toString(), 'atOnce' => false, 'started' => false, 'busy' => false];
        }

        if ($import->isBeingSetUp() === false || $import->cellsWipedAt !== null) {
            throw new DataImportNotFound();
        }

        $batchId = $message->ofBatchId !== null && Uuid::isValid($message->ofBatchId) ? strtolower($message->ofBatchId) : null;

        if (
            in_array($message->code, self::CODES, true) === false
            || in_array($message->action, [DataImportGroupAnswer::ADD_ANYWAY, DataImportGroupAnswer::LEAVE_OUT], true) === false
            || ($message->beforeAdd && $message->code !== ImportRowRefusal::UnusualTime->value)
        ) {
            throw new DataImportNotFound();
        }

        // One answer at a time per reason: "add all" and "leave them out" side by side would split the rows
        $open = $this->batchRepository->findRunningGroupAnswer($import, $message->code);

        if ($open !== null) {
            return ['id' => $open->id->toString(), 'atOnce' => false, 'started' => false, 'busy' => true];
        }

        $rows = $message->beforeAdd
            ? count($this->rowRepository->idsOfTimedReady($import, null, DataImportLimits::MAX_ROWS))
            : $this->rowRepository->countRefusedWith($import, $message->code, $batchId);

        if ($rows === 0) {
            return null;
        }

        $now = $this->clock->now();
        $this->batchRepository->saveGroupAnswer(new DataImportGroupAnswer(
            id: Uuid::fromString($message->requestId),
            import: $import,
            code: $message->code,
            action: $message->action,
            ofBatchId: $batchId === null ? null : Uuid::fromString($batchId),
            beforeAdd: $message->beforeAdd,
            rowsTotal: $rows,
            startedAt: $now,
        ));
        $import->workedOn($now);

        return [
            'id' => strtolower($message->requestId),
            // Before an "Add" every row is shown to the time verification - never in a request
            'atOnce' => $message->beforeAdd === false && $rows <= self::AT_ONCE,
            'started' => true,
            'busy' => false,
        ];
    }
}
