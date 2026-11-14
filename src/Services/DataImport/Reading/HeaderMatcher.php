<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\DurationUnit;
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
 * "no_of_pieces" and "NoOfPieces" are one header.
 *
 * - A header that IS one of a field's words is an exact guess. It is `sure` - fit to map a column by itself - only
 *   when no other field has the word and the word does not mean several things.
 * - A header that only HOLDS a word among others is a weaker guess, never sure - and no guess at all when the word is
 *   a generic one ("time" in "Start time", "date" in "Date added", "with" in "With box") or the header carries a
 *   qualifier that makes it another thing ("Best …", "Goal …", "… sold"). Only the words of a formula
 *   ("pieces per minute", "rank") are looked for regardless.
 * - A unit in the header ("Time (min)", "Hours") is handed out with the guess, not thrown away.
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
        'condition' => ['new or used', 'new/used', 'new / second-hand'],
        'my_tags' => ['tag', 'hashtags', 'theme', 'keywords', 'štítek', 'etiqueta', 'étiquette'],
        'price_paid' => ['amount paid', 'purchase cost'],
        'started_on' => ['begin date', 'date begun', 'begun', 'date of start'],
        // French "note" is a mark out of five as often as a remark: the two tie
        'how_i_liked_it' => ['note'],
    ];

    /**
     * Words that say what a column is only when they are the whole header: inside a longer one they are a part of
     * another thing ("Start time", "Date added", "Who gave it", "mit Rahmen", "Sell price", "Wish list"). Folded.
     */
    private const array WHOLE_HEADER_ONLY = [
        'time', 'zeit', 'cas', 'tiempo', 'temps', 'doba', 'dauer', 'duration', 'タイム', '時間',
        'date', 'datum', 'fecha', 'when', 'kdy', 'wann', 'cuando', 'quand', '日付',
        'name', 'nombre', 'nom', 'jmeno', 'puzzle', '名前',
        'with', 'mit', 'con', 'avec', 'who', 'team', 'tym', 'equipo', 'equipe', 'partner', 'pareja',
        'list', 'liste', 'lista', 'seznam', 'pile', 'stapel', 'group', 'folder',
        'price', 'prix', 'precio', 'preis', 'cena', 'cost', 'paid',
        'code', 'kod', 'codigo', 'ref', 'reference', 'referencia', 'model',
        'count', 'anzahl', 'cantidad', 'number', 'size', 'grosse', 'groesse', 'taille', 'tamano', 'velikost',
        'type', 'typ', 'mode', 'modus', 'status', 'stav', 'stand', 'state', 'estado', 'statut',
        'place', 'lugar', 'lieu', 'ort', 'misto', 'box', 'shop', 'store', 'source',
        'year', 'rok', 'jahr', 'ano', 'annee',
        'try', 'run', 'done', 'gone', 'have', 'own', 'keep', 'kept', 'people', 'players', 'personen', 'personas', 'personnes',
        'note', 'nota', 'missing', 'chybi', 'faltan', 'improve', 'released', 'purchased', 'stored', 'started',
    ];

    /**
     * Words that make a header another thing than the field one of its words names: no weaker guess is made from a
     * header that carries one (a formula's words excepted). Folded; the last ones are found inside Japanese headers.
     */
    private const array QUALIFIERS = [
        'start', 'starting', 'begin', 'end', 'ending', 'goal', 'target', 'best', 'worst', 'record', 'pb', 'previous',
        'last', 'next', 'added', 'release', 'creation', 'created', 'updated', 'modified', 'sold', 'sell', 'selling',
        'sale', 'gave', 'given', 'gift', 'wish', 'wanted', 'frame', 'expected', 'estimated', 'planned', 'due',
        'zacatek', 'konec', 'cil', 'nejlepsi', 'pridano', 'prodano', 'prodej',
        'ende', 'ziel', 'beste', 'bester', 'rahmen', 'verkauf', 'verkauft', 'wunsch',
        'inicio', 'fin', 'objetivo', 'mejor', 'marco', 'venta', 'vendido', 'deseo',
        'debut', 'objectif', 'meilleur', 'cadre', 'vente', 'vendu', 'souhait',
        'スタート', '開始', '終了', '目標', 'ベスト', '販売',
    ];

    /**
     * Whole headers that mean several things, whatever the lists say: a guess for one of them is never sure. Folded.
     * (Words two fields share - "stav", "place", "note", "completed" - tie by themselves.)
     */
    private const array AMBIGUOUS_HEADERS = [
        'name', 'nombre', 'nom', 'jmeno', '名前',
        'count', 'anzahl', 'type', 'typ', 'size', 'grosse', 'groesse', 'taille', 'tamano', 'velikost',
        'status', 'state', 'year', 'rok', 'jahr', 'ano', 'annee', '年',
        'completed', 'finished', 'solved', 'done', 'place', 'note',
    ];

    /**
     * Unit words of a time column's header, folded. Any of them in brackets is a unit ("Time (min)", "Čas [h]");
     * as the whole header only the long ones are ("Minutes", "Hours" - never "M", "Min" or "S").
     */
    private const array UNITS = [
        'seconds' => ['s', 'sec', 'secs', 'second', 'seconds', 'sek', 'sekund', 'sekundy', 'sekunden', 'seg', 'segundos', 'secondes', '秒'],
        'minutes' => ['m', 'min', 'mins', 'minute', 'minutes', 'minut', 'minuty', 'minuten', 'minutos', 'mn', '分'],
        'hours' => ['h', 'hr', 'hrs', 'hour', 'hours', 'hod', 'hodin', 'hodiny', 'std', 'stunden', 'horas', 'heures', '時間'],
    ];

    private const array UNIT_HEADERS = [
        'second', 'seconds', 'sekund', 'sekundy', 'sekunden', 'segundos', 'secondes', '秒',
        'minute', 'minutes', 'mins', 'minut', 'minuty', 'minuten', 'minutos', '分',
        'hour', 'hours', 'hrs', 'hodin', 'hodiny', 'stunden', 'horas', 'heures',
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

        [$named, $unitHint] = self::withoutUnit($header, $words);

        if ($named === '') {
            // The header is nothing but a unit: "Minutes", "Hours"
            return $unitHint === null ? [] : [new FieldGuess(ImportField::Time, null, FieldGuess::EXACT, true, $unitHint)];
        }

        // "Time (h:mm:ss)", "Price [CZK]": what stands in brackets explains the header, it is not its name
        $readings = array_values(array_unique([$words, $named]));
        $hasQualifier = self::hasQualifier($words);

        $guesses = [];

        foreach (self::index() as $position => $entry) {
            foreach ($readings as $reading) {
                $score = self::score($reading, str_replace(' ', '', $reading), $entry, $hasQualifier);

                if ($score !== null && $score > ($guesses[$entry['key']]['score'] ?? 0)) {
                    $guesses[$entry['key']] = ['score' => $score, 'position' => $position, 'entry' => $entry];
                }
            }
        }

        // The best first; of equal ones the field that comes first in ImportField, then in StandardCustomField
        usort($guesses, static fn (array $a, array $b): int => [$b['score'], $a['position']] <=> [$a['score'], $b['position']]);

        $exact = count(array_filter($guesses, static fn (array $guess): bool => $guess['score'] === FieldGuess::EXACT));
        $ambiguous = array_intersect($readings, self::AMBIGUOUS_HEADERS) !== [];

        return array_map(
            static fn (array $guess): FieldGuess => new FieldGuess(
                $guess['entry']['field'],
                $guess['entry']['readyMade'],
                $guess['score'],
                $guess['score'] === FieldGuess::EXACT && $exact === 1 && $ambiguous === false,
                $guess['entry']['field'] === ImportField::Time ? $unitHint : null,
            ),
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
     * The header's words without what stands in brackets and without a unit at its end ("Time (min)", "Time [s]",
     * "Time in minutes", "Minutes"), and that unit.
     *
     * @return array{string, null|DurationUnit}
     */
    private static function withoutUnit(string $header, string $words): array
    {
        $folded = SearchText::fold($header);
        $unit = null;

        if (preg_match_all('/\(([^()]*)\)|\[([^\[\]]*)\]/u', $folded, $brackets, PREG_SET_ORDER) > 0) {
            foreach ($brackets as $bracket) {
                $inside = self::words(($bracket[1] ?? '') . ($bracket[2] ?? ''));
                $unit ??= self::unit(preg_replace('/^in /', '', $inside) ?? $inside, false);
            }
        }

        $named = self::words(preg_replace('/\([^()]*\)|\[[^\[\]]*\]/u', ' ', $folded) ?? '');

        if ($named === '') {
            // Nothing but brackets: the header is what stands in them
            return [$words, null];
        }

        if ($unit === null && preg_match('/^(.+) in (\S+)$/u', $named, $match) === 1 && self::unit($match[2], true) !== null) {
            return [$match[1], self::unit($match[2], true)];
        }

        if ($unit === null && self::unit($named, true) !== null) {
            return ['', self::unit($named, true)];
        }

        return [$named, $unit];
    }

    private static function unit(string $word, bool $wholeHeader): null|DurationUnit
    {
        if ($wholeHeader && in_array($word, self::UNIT_HEADERS, true) === false) {
            return null;
        }

        foreach (self::UNITS as $unit => $words) {
            if (in_array($word, $words, true)) {
                return DurationUnit::from($unit);
            }
        }

        return null;
    }

    private static function hasQualifier(string $words): bool
    {
        if (array_intersect(explode(' ', $words), self::QUALIFIERS) !== []) {
            return true;
        }

        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $words) !== 1) {
            return false;
        }

        foreach (self::QUALIFIERS as $qualifier) {
            if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $qualifier) === 1 && str_contains($words, $qualifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{key: string, field: ImportField, readyMade: null|StandardCustomField, words: string, joined: string, spaced: bool, wholeOnly: bool} $entry
     */
    private static function score(string $words, string $joined, array $entry, bool $hasQualifier): null|int
    {
        if ($entry['words'] === $words || $entry['joined'] === $joined) {
            return FieldGuess::EXACT;
        }

        $computed = $entry['field'] === ImportField::Computed;

        if ($entry['wholeOnly'] || ($hasQualifier && $computed === false)) {
            return null;
        }

        // As a whole word - in a script without spaces between words, anywhere
        $held = $entry['spaced']
            ? str_contains(' ' . $words . ' ', ' ' . $entry['words'] . ' ')
            : str_contains($words, $entry['words']);

        if ($held === false) {
            return null;
        }

        $score = 50 + (int) round(40 * mb_strlen($entry['words']) / max(1, mb_strlen($words)));

        if ($computed) {
            $score += self::COMPUTED_BONUS;
        }

        return min($score, FieldGuess::CONTAINED_BELOW - 1);
    }

    /**
     * Every header word with its field, in the order equal guesses come in. Built once per process from constants -
     * nothing of a request is in it, so there is nothing to reset between requests.
     *
     * @return list<array{key: string, field: ImportField, readyMade: null|StandardCustomField, words: string, joined: string, spaced: bool, wholeOnly: bool}>
     */
    private static function index(): array
    {
        /** @var null|list<array{key: string, field: ImportField, readyMade: null|StandardCustomField, words: string, joined: string, spaced: bool, wholeOnly: bool}> $index */
        static $index = null;

        if ($index !== null) {
            return $index;
        }

        $index = [];
        $seen = [];
        $add = static function (ImportField $field, null|StandardCustomField $readyMade, string $word) use (&$index, &$seen): void {
            $words = self::words($word);
            $key = $field->value . ($readyMade === null ? '' : ':' . $readyMade->value);

            if ($words === '' || isset($seen[$key . '|' . $words])) {
                return;
            }

            $seen[$key . '|' . $words] = true;
            $index[] = [
                'key' => $key,
                'field' => $field,
                'readyMade' => $readyMade,
                'words' => $words,
                'joined' => str_replace(' ', '', $words),
                // Japanese is written without spaces: a word is found inside a header, not between spaces
                'spaced' => preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $words) !== 1,
                'wholeOnly' => in_array($words, self::WHOLE_HEADER_ONLY, true),
            ];
        };

        $addOfLanguage = static function (ImportField $field, null|StandardCustomField $readyMade, string $language, string $word) use ($add): void {
            $add($field, $readyMade, $word);

            // German is also typed without its umlauts: "Groesse", "Kuenstler" (the fold alone makes them "grosse", "kunstler")
            if ($language === 'de' && preg_match('/[äöüÄÖÜ]/u', $word) === 1) {
                $add($field, $readyMade, strtr($word, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue']));
            }
        };

        foreach (ImportField::cases() as $field) {
            foreach ($field->headerWords() as $language => $ofLanguage) {
                foreach ($ofLanguage as $word) {
                    $addOfLanguage($field, null, $language, $word);
                }
            }
        }

        foreach (StandardCustomField::cases() as $readyMade) {
            foreach ($readyMade->headerWords() as $language => $ofLanguage) {
                foreach ($ofLanguage as $word) {
                    $addOfLanguage(ImportField::ReadyMadeField, $readyMade, $language, $word);
                }
            }

            foreach (self::MORE_READY_MADE_WORDS[$readyMade->value] ?? [] as $word) {
                $add(ImportField::ReadyMadeField, $readyMade, $word);
            }
        }

        return $index;
    }
}
