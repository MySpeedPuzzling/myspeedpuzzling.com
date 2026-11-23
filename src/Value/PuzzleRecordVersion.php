<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;

/**
 * A short fingerprint of a puzzle's catalogue record - names (their languages included), brand, pieces, codes, "no
 * barcode on the box" and image. Every form holding the whole record (moderator edit, change request review, approval,
 * merge review) sends the version it was loaded with; the handler compares it with the puzzle as it is before changing
 * anything, so a save never silently overwrites what somebody else saved in between (docs/features/puzzle-names/README.md).
 *
 * The check runs inside the handler's transaction; two saves checking at the same moment are kept apart by the lock
 * every puzzle-changing message takes (SerializedByLock with lockKey()).
 */
readonly final class PuzzleRecordVersion
{
    /**
     * The lock key of every message that changes a puzzle's record - one key per puzzle, so they wait for each other.
     */
    public static function lockKey(string $puzzleId): string
    {
        return 'puzzle-' . strtolower($puzzleId);
    }

    public static function of(
        string $name,
        null|string $nameLanguage,
        PuzzleNames $alternativeNames,
        null|string $manufacturerId,
        int $piecesCount,
        null|string $ean,
        null|string $identificationNumber,
        null|string $image,
        // Part of the fingerprint only while set - every record version without it stays what it was
        bool $eanAbsent = false,
    ): string {
        $record = [
            $name,
            $nameLanguage,
            $alternativeNames->toArray(),
            $manufacturerId !== null ? strtolower($manufacturerId) : null,
            $piecesCount,
            $ean,
            $identificationNumber,
            $image,
        ];

        if ($eanAbsent) {
            $record[] = 'ean-absent';
        }

        return substr(hash('sha256', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    public static function ofPuzzle(Puzzle $puzzle): string
    {
        return self::of(
            name: $puzzle->name,
            nameLanguage: $puzzle->nameLanguage,
            alternativeNames: $puzzle->alternativeNames(),
            manufacturerId: $puzzle->manufacturer?->id->toString(),
            piecesCount: $puzzle->piecesCount,
            ean: $puzzle->ean,
            identificationNumber: $puzzle->identificationNumber,
            image: $puzzle->image,
            eanAbsent: $puzzle->isEanAbsent(),
        );
    }

    /**
     * @param null|string $loadedVersion What the form was loaded with - null checks nothing (the internal API, a form
     *                                   rendered by the release before)
     *
     * @throws PuzzleChangedMeanwhile
     */
    public static function assertUnchanged(Puzzle $puzzle, null|string $loadedVersion): void
    {
        if ($loadedVersion !== null && $loadedVersion !== self::ofPuzzle($puzzle)) {
            throw new PuzzleChangedMeanwhile();
        }
    }
}
