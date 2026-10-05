<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use RuntimeException;
use SpeedPuzzling\Web\Message\CanonicalizePuzzleCodes;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanup;
use SpeedPuzzling\Web\Value\PuzzleCodesReportRow;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Returns every field it wrote, with its value before and after, and appends them to the run's undo file before the
 * batch commits (the doctrine_transaction middleware commits after this returns): a crash after the commit never loses
 * them, and the row of a batch rolled back only restores the value it still has. The batch is locked for update and
 * every puzzle decided again from its values as they are now: a moderator's edit committing between the command
 * reading the batch and this write is never overwritten, and a value no longer format-only is left alone.
 */
#[AsMessageHandler]
readonly final class CanonicalizePuzzleCodesHandler
{
    // How the undo file writes NULL - an empty cell is an empty string
    public const string UNDO_NULL = '\N';

    public function __construct(
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @return list<array{puzzleId: string, field: string, before: null|string, after: null|string}>
     */
    public function __invoke(CanonicalizePuzzleCodes $message): array
    {
        $written = [];

        foreach ($this->puzzleRepository->findByIdsForUpdate($message->puzzleIds) as $puzzle) {
            $cleanup = PuzzleCodesCleanup::of($puzzle->id->toString(), $puzzle->name, $puzzle->ean, $puzzle->identificationNumber);

            if ($cleanup->writeEans === false && $cleanup->writeBrandCodes === false) {
                continue;
            }

            $before = [PuzzleCodesReportRow::FIELD_EAN => $puzzle->ean, PuzzleCodesReportRow::FIELD_BRAND_CODES => $puzzle->identificationNumber];

            $puzzle->canonicalizeProductIdentifiers($cleanup->writeEans, $cleanup->writeBrandCodes);

            $after = [PuzzleCodesReportRow::FIELD_EAN => $puzzle->ean, PuzzleCodesReportRow::FIELD_BRAND_CODES => $puzzle->identificationNumber];

            foreach ($before as $field => $value) {
                if ($value !== $after[$field]) {
                    $written[] = ['puzzleId' => $puzzle->id->toString(), 'field' => $field, 'before' => $value, 'after' => $after[$field]];
                }
            }
        }

        if ($written !== []) {
            $this->appendToUndo($message->undoPath, $written);
        }

        return $written;
    }

    /**
     * @param list<array{puzzleId: string, field: string, before: null|string, after: null|string}> $written
     *
     * @throws RuntimeException The undo file cannot be written - the batch is rolled back
     */
    private function appendToUndo(string $undoPath, array $written): void
    {
        $undo = fopen($undoPath, 'ab');

        if ($undo === false) {
            throw new RuntimeException(sprintf('The undo file "%s" cannot be written - nothing of the batch is saved.', $undoPath));
        }

        try {
            foreach ($written as $change) {
                $row = [$change['puzzleId'], $change['field'], $change['before'] ?? self::UNDO_NULL, $change['after'] ?? self::UNDO_NULL];

                if (fputcsv($undo, $row, escape: '') === false) {
                    throw new RuntimeException(sprintf('The undo file "%s" cannot be written - nothing of the batch is saved.', $undoPath));
                }
            }

            if (fflush($undo) === false) {
                throw new RuntimeException(sprintf('The undo file "%s" cannot be written - nothing of the batch is saved.', $undoPath));
            }
        } finally {
            fclose($undo);
        }
    }
}
