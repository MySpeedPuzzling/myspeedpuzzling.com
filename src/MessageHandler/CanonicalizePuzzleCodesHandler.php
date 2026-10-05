<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CanonicalizePuzzleCodes;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Returns how many EAN and brand code fields it wrote. The batch is locked for update and every puzzle decided again
 * from its values as they are now: a moderator's edit committing between the command reading the batch and this
 * write is never overwritten, and a value no longer format-only is left alone.
 */
#[AsMessageHandler]
readonly final class CanonicalizePuzzleCodesHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @return array{ean: int, identification_number: int}
     */
    public function __invoke(CanonicalizePuzzleCodes $message): array
    {
        $written = ['ean' => 0, 'identification_number' => 0];

        foreach ($this->puzzleRepository->findByIdsForUpdate($message->puzzleIds) as $puzzle) {
            $cleanup = PuzzleCodesCleanup::of($puzzle->id->toString(), $puzzle->name, $puzzle->ean, $puzzle->identificationNumber);

            if ($cleanup->writable === false) {
                continue;
            }

            $puzzle->updateProductIdentifiers($cleanup->eans, $cleanup->brandCodes);

            $written['ean'] += $cleanup->eanChanges ? 1 : 0;
            $written['identification_number'] += $cleanup->brandCodesChange ? 1 : 0;
        }

        return $written;
    }
}
