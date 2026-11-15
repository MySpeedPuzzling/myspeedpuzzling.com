<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * The export of an app we know (docs/features/data-import/README.md "Reading a file", columns.md "Known exports"):
 * recognised by its headers, nothing to map. Each entry says which headers identify it, what every column is, which
 * column is the source's own row id, which words decide what a row is, and which tags mean something.
 *
 * The first two: Puzzle Tracker's CSV, and MySpeedPuzzling's own results export (docs/features/data-export.md) in the
 * formats the reader takes (.csv, .xlsx). Another app is one more entry here, added when a player sends its export.
 *
 * The ids are stored with a sheet's mapping - never rename one.
 */
final readonly class KnownExportLayout
{
    public const string PUZZLE_TRACKER = 'puzzle_tracker';
    public const string MYSPEEDPUZZLING_RESULTS = 'myspeedpuzzling_results';

    /** A column of our own export that holds one of the player's fields: `custom:<key>`, `custom:own:<name>` */
    private const string OWN_FIELD_PREFIX = 'custom:';

    /**
     * @param list<string> $identifying headers (as HeaderMatcher::words() makes them) that must all be there
     * @param array<string, array{field?: ImportField, readyMade?: StandardCustomField, role?: ImportColumnRole, unit?: DurationUnit}> $columns
     *        header words => what the column is; a header that is not listed, or listed with nothing, is not imported
     * @param array<string, ImportWordMeaning> $statusWords folded word of the status column => what the row is
     * @param array<string, ImportWordMeaning> $tagPatterns a regular expression over a folded tag => what it means
     * @param list<string> $pictureHosts the hosts a picture of the export may be fetched from (stage 7)
     */
    private function __construct(
        public string $id,
        public array $identifying,
        public array $columns,
        public DataImportSheetKind $kind,
        public DataImportRowMeaning $rowMeaning,
        public array $statusWords = [],
        public array $tagPatterns = [],
        public null|DateOrder $dateOrder = null,
        /** Texts carry the apostrophe our export puts in front of a formula sign (SpreadsheetSafeValue) */
        public bool $formulaGuarded = false,
        public array $pictureHosts = [],
    ) {
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::puzzleTracker(), self::mySpeedPuzzlingResults()];
    }

    public static function byId(string $id): null|self
    {
        foreach (self::all() as $layout) {
            if ($layout->id === $id) {
                return $layout;
            }
        }

        return null;
    }

    /**
     * The export a header row is of - null when it is nobody's we know.
     *
     * @param array<array-key, string> $headers as the sheet has them
     */
    public static function matching(array $headers): null|self
    {
        $words = array_map(HeaderMatcher::words(...), $headers);

        foreach (self::all() as $layout) {
            if (array_diff($layout->identifying, $words) === []) {
                return $layout;
            }
        }

        return null;
    }

    /**
     * What a column of the export is.
     */
    public function column(int $number, string $header): MappedColumn
    {
        $known = $this->columns[HeaderMatcher::words($header)] ?? [];
        $readyMade = $known['readyMade'] ?? null;

        if ($this->id === self::MYSPEEDPUZZLING_RESULTS && str_starts_with(trim($header), self::OWN_FIELD_PREFIX)) {
            // `custom:price_paid` is a ready-made field by its key; `custom:own:<name>` is the player's own - a new
            // field, which the screen of the next stage offers
            $readyMade = StandardCustomField::tryFrom(substr(trim($header), strlen(self::OWN_FIELD_PREFIX)));
        }

        return new MappedColumn(
            $number,
            $header,
            $readyMade !== null ? ImportField::ReadyMadeField : ($known['field'] ?? null),
            ColumnCertainty::Certain,
            MappedColumn::BY_KNOWN_EXPORT,
            $readyMade,
            $known['role'] ?? null,
            unit: $known['unit'] ?? null,
        );
    }

    public function tagMeaning(string $foldedTag): null|ImportWordMeaning
    {
        foreach ($this->tagPatterns as $pattern => $meaning) {
            if (preg_match($pattern, $foldedTag) === 1) {
                return $meaning;
            }
        }

        return null;
    }

    /**
     * The official export of the app "Puzzle Tracker" (research.md "The fourth file"): one row per puzzle or solve,
     * a UUID per row, a status, times as `h:mm:ss` without leading zeros, dates as `dd.mm.yyyy`, the player's own
     * system in hashtags.
     */
    private static function puzzleTracker(): self
    {
        $custom = static fn (StandardCustomField $field): array => ['readyMade' => $field];

        return new self(
            self::PUZZLE_TRACKER,
            ['id', 'name', 'brand', 'number of elements', 'status', 'time', 'start date', 'end date', 'bar code', 'tags'],
            [
                'id' => ['role' => ImportColumnRole::SourceRowId],
                'name' => ['field' => ImportField::PuzzleName],
                'brand' => ['field' => ImportField::Brand],
                'number of elements' => ['field' => ImportField::Pieces],
                'status' => ['field' => ImportField::Status],
                'time' => ['field' => ImportField::Time],
                // When the row was typed into the app - not when anything happened
                'creation date' => [],
                'start date' => $custom(StandardCustomField::StartedOn),
                'end date' => ['field' => ImportField::SolvedOn],
                'satisfaction' => $custom(StandardCustomField::HowILikedIt),
                'difficulty' => $custom(StandardCustomField::HowHardItFelt),
                'quality' => $custom(StandardCustomField::Quality),
                'price' => $custom(StandardCustomField::PricePaid),
                // "Sold for" is not a ready-made field yet (README "Ready-made fields")
                'sell price' => [],
                'missing pieces' => $custom(StandardCustomField::MissingPieces),
                'place of purchase' => $custom(StandardCustomField::BoughtFrom),
                'artist' => $custom(StandardCustomField::Artist),
                // Brand new / Second-hand
                'puzzle type' => $custom(StandardCustomField::Condition),
                'bar code' => ['field' => ImportField::Barcode],
                'tags' => $custom(StandardCustomField::MyTags),
                'notes' => $custom(StandardCustomField::PrivateNotes),
                'picture id' => [],
                'picture url' => ['role' => ImportColumnRole::PictureUrl],
            ],
            DataImportSheetKind::Results,
            DataImportRowMeaning::ByColumn,
            [
                // A completed puzzle is one the player has, unless a tag says it went (#xSOLD …)
                'completed' => ImportWordMeaning::Both,
                'waiting list' => ImportWordMeaning::Owned,
                'wish list' => ImportWordMeaning::WishList,
            ],
            [
                '/^#?0?1[-_ ]?(individual|solo)$/' => ImportWordMeaning::Solo,
                '/^#?0?2[-_ ]?(pair|duo)$/' => ImportWordMeaning::Pair,
                '/^#?0?3[-_ ]?team$/' => ImportWordMeaning::Team,
                '/^#x[-_ ]?(sold|returned|gifted)$/' => ImportWordMeaning::NotMine,
            ],
            DateOrder::DayMonthYear,
        );
    }

    /**
     * Our own results export (PuzzlerDataExporter, ExportableSolvingTime::toArray()): `result_id` is the row's
     * identity, `puzzle_id` the catalogue puzzle, the time is in seconds, and everything MySpeedPuzzling worked out
     * for the export is not read back.
     */
    private static function mySpeedPuzzlingResults(): self
    {
        $computed = ['field' => ImportField::Computed];

        return new self(
            self::MYSPEEDPUZZLING_RESULTS,
            ['result id', 'puzzle id', 'puzzle name', 'pieces count', 'seconds to solve', 'finished at', 'tracked at', 'type'],
            [
                'result id' => ['role' => ImportColumnRole::SourceRowId],
                'puzzle id' => ['role' => ImportColumnRole::PuzzleId],
                'puzzle name' => ['field' => ImportField::PuzzleName],
                'brand name' => ['field' => ImportField::Brand],
                'pieces count' => ['field' => ImportField::Pieces],
                'seconds to solve' => ['field' => ImportField::Time, 'unit' => DurationUnit::Seconds],
                'time formatted' => $computed,
                'finished at' => ['field' => ImportField::SolvedOn],
                'tracked at' => [],
                'type' => ['field' => ImportField::SolvingType],
                'first attempt' => ['field' => ImportField::FirstTry],
                'unboxed' => ['field' => ImportField::SolvedWithoutBox],
                'players count' => $computed,
                'team members' => ['field' => ImportField::SolvedWith],
                'finished puzzle photo url' => [],
                // In our export this IS the result's public comment
                'comment' => ['field' => ImportField::PublicComment],
                'puzzle fastest time' => $computed,
                'puzzle fastest time formatted' => $computed,
                'puzzle average time' => $computed,
                'puzzle average time formatted' => $computed,
                'player rank' => $computed,
                'puzzle total solved' => $computed,
                'ppm' => $computed,
                'event id' => [],
                'event name' => [],
                'event series id' => [],
                'event series name' => [],
                'msp rating' => $computed,
                'msp rating reason' => $computed,
                'msp rating puzzle first try solvers' => $computed,
            ],
            DataImportSheetKind::Results,
            DataImportRowMeaning::Result,
            formulaGuarded: true,
        );
    }
}
