<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CanonicalizePuzzleCodes;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanup;
use SpeedPuzzling\Web\Value\PuzzleCodesReportRow;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Returns every field it wrote, with its value before and after - the command's undo file. The batch is locked for
 * update and every puzzle decided again from its values as they are now: a moderator's edit committing between the
 * command reading the batch and this write is never overwritten, and a value no longer format-only is left alone.
 */
#[AsMessageHandler]
readonly final class CanonicalizePuzzleCodesHandler
{
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

        return $written;
    }
}
