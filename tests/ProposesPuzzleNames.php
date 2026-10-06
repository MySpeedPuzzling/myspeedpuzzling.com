<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A change request of the names only - "Suggest a change" with one more name in the names editor and nothing else
 * touched.
 */
trait ProposesPuzzleNames
{
    /**
     * @return string the change request's id
     */
    protected static function proposeOtherName(string $puzzleId, string $playerId, string $name, null|string $language): string
    {
        $changeRequestId = Uuid::uuid7()->toString();
        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get($puzzleId);
        $names = $puzzle->alternativeNames();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: $puzzleId,
            reporterId: $playerId,
            proposedName: $puzzle->name,
            proposedBrand: $puzzle->manufacturer?->id->toString(),
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEans: $puzzle->eans(),
            proposedBrandCodes: $puzzle->brandCodes(),
            proposedPhoto: null,
            originalAlternativeNames: $names,
            originalNameLanguage: $puzzle->nameLanguage,
            proposedAlternativeNames: new PuzzleNames([...$names->all(), new PuzzleName($name, $language)]),
            proposedNameLanguage: $puzzle->nameLanguage,
        ));

        return $changeRequestId;
    }
}
