<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\CellInputKind;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\PiecesProblem;
use SpeedPuzzling\Web\Value\DataImport\ReadPieces;

/**
 * Reads one cell of a pieces column (docs/features/data-import/README.md "Pieces", docs/features/data-import/value-readers.md
 * "Pieces" - every rule and its examples).
 *
 * A piece count is a whole number from 1 to MOST_PIECES. A thousands mark and a word for "pieces" are read; a
 * multipack ("13x99"), an unknown count ("?", "~1000"), a number that is no count ("731.78"), zero, a negative or an
 * absurd number are problems with what was found handed out - one row never becomes several puzzles and a count is
 * never guessed. Never throws.
 */
final readonly class PiecesReader
{
    /** The largest puzzle sold has 60,000 pieces */
    public const int MOST_PIECES = 60_000;

    // "pieces" in en / cs / de / es / fr / ja, in lower case without accents
    private const string WORD = '(?:pieces?|pcs?|pzs?|pzas?|p|elements?|el|dilk[uy]|dilek|dilku|kus[uy]?|ks|teile?n?|tlg|tl|stuck|stk|piezas?|ピース|片|個|枚)\.?';

    private const string TIMES = '[x×*✕✖+]';

    public function read(CellInput $cell): ReadPieces
    {
        return match ($cell->kind) {
            CellInputKind::Blank => ReadPieces::noPieces(),
            CellInputKind::Text => $this->text($cell->asText()),
            CellInputKind::Number => $this->number($cell->asNumber()),
            CellInputKind::Duration, CellInputKind::TimeOfDay, CellInputKind::Date, CellInputKind::Boolean
                => ReadPieces::problem(PiecesProblem::Unreadable),
        };
    }

    private function number(int|float $number): ReadPieces
    {
        if (is_int($number)) {
            return self::whole($number, false);
        }

        if (is_nan($number) || is_infinite($number)) {
            return ReadPieces::problem(PiecesProblem::Unreadable);
        }

        if ($number < 0) {
            return ReadPieces::problem(PiecesProblem::Negative, ImportText::ofNumber($number));
        }

        if (floor($number) !== $number) {
            return ReadPieces::problem(PiecesProblem::NotWhole, ImportText::ofNumber($number));
        }

        return $number > PHP_INT_MAX ? ReadPieces::problem(PiecesProblem::TooMany) : self::whole((int) $number, false);
    }

    private function text(string $text): ReadPieces
    {
        if (ImportText::isTooLong($text)) {
            return ReadPieces::problem(PiecesProblem::Unreadable);
        }

        $typed = ImportText::clean($text);

        if ($typed === '') {
            return ReadPieces::noPieces();
        }

        $value = ImportText::plain($typed);
        $worded = false;

        if (preg_match('/^(.*\d)\s*' . self::WORD . '$/u', $value, $match) === 1) {
            $value = $match[1];
            $worded = true;
        }

        if (preg_match('/^\d{1,9}$/', $value) === 1) {
            return self::whole((int) $value, $worded);
        }

        // "1,000", "1 000", "1.000", "12'000": the same mark before every group of three digits. In a pieces column a
        // dot before exactly three digits is a thousands mark, never a decimal point - a piece count is whole
        if (preg_match('/^\d{1,3}([,. \'’])\d{3}(?:\1\d{3})?$/u', $value) === 1) {
            return self::whole((int) preg_replace('/\D+/u', '', $value), true);
        }

        if (preg_match('/^(\d{1,9})[.,](\d{1,9})$/', $value, $match) === 1) {
            return ltrim($match[2], '0') === ''
                ? self::whole((int) $match[1], true)
                : ReadPieces::problem(PiecesProblem::NotWhole, $typed);
        }

        if (preg_match('/^[-−–]\s?\d+(?:[.,]\d+)?$/u', $value) === 1) {
            return ReadPieces::problem(PiecesProblem::Negative, $typed);
        }

        $parts = preg_split('/\s*' . self::TIMES . '\s*/u', $value) ?: [];

        if (count($parts) >= 2 && $parts === array_filter($parts, static fn (string $part): bool => preg_match('/^\d{1,6}$/', $part) === 1)) {
            return ReadPieces::problem(PiecesProblem::Multipack, $typed, array_map(intval(...), $parts));
        }

        if (preg_match('/\?|^(?:~|≈|±|\+\/-|ca\.?|cca\.?|circa|approx\.?|about|around|asi|etwa|unos|environ|約)\s*\d|\d\s*\+$/u', $value) === 1) {
            preg_match_all('/\d{1,9}/', $value, $numbers);

            return ReadPieces::problem(PiecesProblem::Unknown, $typed, array_map(intval(...), $numbers[0]));
        }

        return ReadPieces::problem(PiecesProblem::Unreadable, $typed);
    }

    private static function whole(int $number, bool $fromText): ReadPieces
    {
        return match (true) {
            $number < 0 => ReadPieces::problem(PiecesProblem::Negative, (string) $number),
            $number === 0 => ReadPieces::problem(PiecesProblem::Zero),
            $number > self::MOST_PIECES => ReadPieces::problem(PiecesProblem::TooMany, null, [$number]),
            $fromText => ReadPieces::fromText($number),
            default => ReadPieces::certain($number),
        };
    }
}
