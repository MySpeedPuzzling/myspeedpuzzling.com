<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A decision about a puzzle of an import can not be taken as sent - nothing was written. `reason`:
 * `unknown_puzzle` (no such puzzle of this import), `not_choosable` (the catalogue puzzle does not exist or may not
 * be shown to the player - hidden, secret, somebody else's unapproved one), `invalid` (a catalogue choice without
 * its puzzle, or another choice with one), `other_piece_count_in_group` (a puzzle with another piece count than the
 * file's can only be chosen one by one), `needs_a_closer_look` (a puzzle confirmed together with others that is not
 * one of "the same puzzle, less proof" - another brand, another piece count, several possible puzzles, a weaker
 * lookup: ImportPuzzleBulkConfirm - it is confirmed on its own).
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class ImportPuzzleChoiceRefused extends \DomainException
{
    public const string UNKNOWN_PUZZLE = 'unknown_puzzle';
    public const string NOT_CHOOSABLE = 'not_choosable';
    public const string INVALID = 'invalid';
    public const string OTHER_PIECE_COUNT_IN_GROUP = 'other_piece_count_in_group';
    public const string NEEDS_A_CLOSER_LOOK = 'needs_a_closer_look';
    /** A puzzle of another piece count chosen alone, without the player's explicit yes to the question */
    public const string OTHER_PIECE_COUNT_UNCONFIRMED = 'other_piece_count_unconfirmed';

    public function __construct(
        public readonly string $reason,
        public readonly string $importPuzzleId,
    ) {
        parent::__construct('The decision about a puzzle of the import was refused: ' . $reason);
    }
}
