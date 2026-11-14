<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\FieldGuess;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\SearchText;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * Guesses from a column's header what the column is (docs/features/data-import/README.md "Recognising columns and
 * values", signal 1; docs/features/data-import/value-readers.md "Headers").
 *
 * The words are data: ImportField::headerWords() for the fields of a puzzle and a result,
 * StandardCustomField::headerWords() for the ready-made fields (plus a few more here), in the six languages of the
 * site. A header is compared folded (SearchText::fold()) and without punctuation or spacing: "No. of Pieces",
 * "no_of_pieces" and "NoOfPieces" are one header. A header that is one of a field's words is an exact guess; a header
 * that holds such a word among others is a weaker one; anything else is no guess - the values have to tell.
 */
final readonly class HeaderMatcher
{
    /** A header that looks worked out by a formula is said so before any field one of its words belongs to */
    private const int COMPUTED_BONUS = 20;

    /**
     * Header words of the ready-made fields beyond their own (StandardCustomField::headerWords()), any language.
     * Notes and comments are private unless the player says otherwise (README D11).
     */
    private const array MORE_READY_MADE_WORDS = [
        'private_notes' => [
            'comment', 'comments', 'remark', 'remarks', 'komentář', 'komentáře', 'bemerkung', 'bemerkungen', 'kommentar',
            'kommentare', 'comentario', 'comentarios', 'commentaire', 'commentaires', 'コメント',
        ],
        'bought_from' => [
            'place of purchase', 'purchase place', 'purchased at', 'bought at', 'where bought', 'where purchased',
            'místo nákupu', 'kaufort', 'lugar de compra', 'lieu d\'achat', '購入場所',
        ],
        'condition' => ['puzzle type', 'new or used', 'new/used', 'new / second-hand'],
        'my_tags' => ['tag', 'hashtags', 'theme', 'keywords', 'štítek', 'etiqueta', 'étiquette'],
        'price_paid' => ['amount paid', 'purchase cost'],
        'started_on' => ['begin date', 'date begun', 'begun', 'date of start'],
    ];

    /**
     * What the header may be, the best guess first - empty for a blank header and for one no word is known in.
     *
     * @return list<FieldGuess>
     */
    public function guess(string $header): array
    {
        if (ImportText::isTooLong($header)) {
            return [];
        }

        $words = self::words($header);

        if ($words === '') {
            return [];
        }

        // "Time (h:mm:ss)", "Price [CZK]": what stands in brackets explains the header, it is not its name
        $unbracketed = self::words(preg_replace('/\([^()]*\)|\[[^\[\]]*\]/u', ' ', SearchText::fold($header)) ?? '');
        $readings = $unbracketed === '' || $unbracketed === $words ? [$words] : [$words, $unbracketed];

        $guesses = [];

        foreach (self::index() as $position => $entry) {
            foreach ($readings as $reading) {
                $score = self::score($reading, str_replace(' ', '', $reading), $entry);

                if ($score !== null && $score > ($guesses[$entry['key']]['score'] ?? 0)) {
                    $guesses[$entry['key']] = ['score' => $score, 'position' => $position, 'entry' => $entry];
                }
            }
        }

        // The best first; of equal ones the field that comes first in ImportField, then in StandardCustomField
        usort($guesses, static fn (array $a, array $b): int => [$b['score'], $a['position']] <=> [$a['score'], $b['position']]);

        return array_map(
            static fn (array $guess): FieldGuess => new FieldGuess($guess['entry']['field'], $guess['entry']['readyMade'], $guess['score']),
            $guesses,
        );
    }

    /**
     * A header as it is compared: folded, "%" and "#" as words, every run of anything but letters and digits one space.
     */
    public static function words(string $header): string
    {
        $folded = str_replace(['%', '#', '№'], [' percent ', ' number ', ' number '], SearchText::fold($header));

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $folded) ?? '');
    }

    /**
     * @param array{key: string, field: ImportField, readyMade: null|StandardCustomField, words: string, joined: string, spaced: bool} $entry
     */
    private static function score(string $words, string $joined, array $entry): null|int
    {
        if ($entry['words'] === $words || $entry['joined'] === $joined) {
            return FieldGuess::EXACT;
        }

        // As a whole word - in a script without spaces between words, anywhere
        $held = $entry['spaced']
            ? str_contains(' ' . $words . ' ', ' ' . $entry['words'] . ' ')
            : str_contains($words, $entry['words']);

        if ($held === false) {
            return null;
        }

        $score = 50 + (int) round(40 * mb_strlen($entry['words']) / max(1, mb_strlen($words)));

        if ($entry['field'] === ImportField::Computed) {
            $score += self::COMPUTED_BONUS;
        }

        return min($score, FieldGuess::CONTAINED_BELOW - 1);
    }

    /**
     * Every header word with its field, in the order equal guesses come in. Built once per process from constants -
     * nothing of a request is in it, so there is nothing to reset between requests.
     *
     * @return list<array{key: string, field: ImportField, readyMade: null|StandardCustomField, words: string, joined: string, spaced: bool}>
     */
    private static function index(): array
    {
        /** @var null|list<array{key: string, field: ImportField, readyMade: null|StandardCustomField, words: string, joined: string, spaced: bool}> $index */
        static $index = null;

        if ($index !== null) {
            return $index;
        }

        $index = [];
        $add = static function (ImportField $field, null|StandardCustomField $readyMade, string $word) use (&$index): void {
            $words = self::words($word);

            if ($words !== '') {
                $index[] = [
                    'key' => $field->value . ($readyMade === null ? '' : ':' . $readyMade->value),
                    'field' => $field,
                    'readyMade' => $readyMade,
                    'words' => $words,
                    'joined' => str_replace(' ', '', $words),
                    // Japanese is written without spaces: a word is found inside a header, not between spaces
                    'spaced' => preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $words) !== 1,
                ];
            }
        };

        foreach (ImportField::cases() as $field) {
            foreach ($field->headerWords() as $ofLanguage) {
                foreach ($ofLanguage as $word) {
                    $add($field, null, $word);
                }
            }
        }

        foreach (StandardCustomField::cases() as $readyMade) {
            foreach ($readyMade->headerWords() as $ofLanguage) {
                foreach ($ofLanguage as $word) {
                    $add(ImportField::ReadyMadeField, $readyMade, $word);
                }
            }

            foreach (self::MORE_READY_MADE_WORDS[$readyMade->value] ?? [] as $word) {
                $add(ImportField::ReadyMadeField, $readyMade, $word);
            }
        }

        return $index;
    }
}
