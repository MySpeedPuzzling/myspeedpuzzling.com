<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\CellInputKind;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ReadYesNo;

/**
 * Reads one cell of a yes/no column (docs/features/data-import/value-readers.md "Yes / no"): a typed yes/no passes
 * through, 1 and 0 are yes and no, and so are the words and marks people use in the six languages of the site -
 * whatever their case, accents or a full stop after them. Any other value is "unknown" and comes back as typed, so the
 * layer above can ask once what it means. Never throws.
 */
final readonly class YesNoReader
{
    /** Longer than any word for yes or no - what is longer is kept for the question, cut to this */
    private const int LONGEST_WORD = 100;

    // Compared in lower case without accents (ImportText::plain())
    private const array YES = [
        'y', 'yes', 'yep', 'yeah', 'true', 't', 'x', 'ok', 'done',
        'ano', 'jo', 'pravda',
        'ja', 'j', 'wahr',
        'si', 's', 'verdadero',
        'oui', 'o', 'vrai',
        'はい', '済', '済み',
        '1', '✓', '✔', '☑', '✅', '○', '◯', '⭕',
    ];

    private const array NO = [
        'n', 'no', 'nope', 'false', 'f',
        'ne', 'nepravda',
        'nein', 'falsch',
        'falso',
        'non', 'faux',
        'いいえ',
        '0', '☐', '✗', '✘', '✕', '✖', '❌', '×',
    ];

    public function read(CellInput $cell): ReadYesNo
    {
        return match ($cell->kind) {
            CellInputKind::Blank => ReadYesNo::blank(),
            CellInputKind::Boolean => ReadYesNo::known($cell->asBoolean()),
            CellInputKind::Number => $this->number($cell->asNumber()),
            CellInputKind::Text => $this->text($cell->asText()),
            CellInputKind::Date => ReadYesNo::unknown($cell->asDate()->format('Y-m-d')),
            CellInputKind::Duration, CellInputKind::TimeOfDay => ReadYesNo::unknown((string) $cell->asSeconds()),
        };
    }

    private function number(int|float $number): ReadYesNo
    {
        return match (true) {
            $number === 1 || $number === 1.0 => ReadYesNo::known(true),
            $number === 0 || $number === 0.0 => ReadYesNo::known(false),
            default => ReadYesNo::unknown(ImportText::ofNumber($number)),
        };
    }

    private function text(string $text): ReadYesNo
    {
        $typed = ImportText::clean(mb_substr(mb_scrub($text, 'UTF-8'), 0, self::LONGEST_WORD * 2, 'UTF-8'));

        if ($typed === '') {
            return ReadYesNo::blank();
        }

        // "Yes.", "no!", "(x)", "[ ]" is not a word
        $word = preg_replace('/^[\s(\["\']+|[\s)\]"\'.!]+$/u', '', ImportText::plain($typed)) ?? '';

        return match (true) {
            in_array($word, self::YES, true) => ReadYesNo::known(true),
            in_array($word, self::NO, true) => ReadYesNo::known(false),
            default => ReadYesNo::unknown(mb_substr($typed, 0, self::LONGEST_WORD, 'UTF-8')),
        };
    }
}
