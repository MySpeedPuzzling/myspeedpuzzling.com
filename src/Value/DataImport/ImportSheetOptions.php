<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * How a sheet's values are read - `data_import_sheet.options` (docs/features/data-import/columns.md "Options"):
 * what the rows, the header, the sheet's name or a known export settled about its times and dates, the player's
 * answers to what they could not settle, and what holds for the whole sheet.
 *
 * Each setting says who settled it (`by`): an answer of the player or of a known export is kept whatever the rows
 * say later; what the rows settled is worked out again whenever the mapping changes.
 */
final readonly class ImportSheetOptions
{
    public const string BY_ROWS = 'rows';
    /** No order fits every row: the one most rows follow - the others are row problems */
    public const string BY_MOST_ROWS = 'most_rows';
    public const string BY_HEADER = 'header';
    public const string BY_SHEET_NAME = 'sheet_name';
    public const string BY_KNOWN_EXPORT = 'known_export';
    public const string BY_PLAYER = 'player';

    private const array BY = [self::BY_ROWS, self::BY_MOST_ROWS, self::BY_HEADER, self::BY_SHEET_NAME, self::BY_KNOWN_EXPORT, self::BY_PLAYER];

    /**
     * @param array<string, array{rule: DurationReadingRule, by: string}> $durations DurationShape's value => how the
     *        time column's values of that shape are read
     * @param null|array{order: DateOrder, by: string} $dateOrder
     * @param null|array{meaning: ShortDateMeaning, by: string} $shortDates
     * @param null|bool $typedDatesSwapped the player's answer to "were the typed dates turned around?"
     * @param null|array{year: int|string, by: string} $year the year of dates written without one - a year, or
     *        ImportQuestion::YEAR_AS_STORED for "the year each cell stores"
     */
    public function __construct(
        public array $durations = [],
        public null|array $dateOrder = null,
        public null|array $shortDates = null,
        public null|bool $typedDatesSwapped = null,
        public null|array $year = null,
        /** Every row of the sheet is this ("every row here is a pair result") */
        public null|PuzzlingType $solvingType = null,
        /** The sheet (of the same import) whose rows give brand and pieces by the puzzle's name */
        public null|string $lookupSheetId = null,
        /** What the sheet's owned puzzles are filed under - null = the player's collection */
        public null|string $collectionLabel = null,
        /**
         * "Who was your partner on this sheet?" - the people of every pair / team row that names nobody, as the
         * player typed them: names or `#codes`, separated by commas (docs/features/data-import/people.md)
         */
        public null|string $partner = null,
        /**
         * The player answered "are these pair results?" - whatever the answer ("each row says" leaves `solvingType`
         * empty): a sheet whose title says pair or team asks once, not again after every change of its columns
         */
        public bool $solvingTypeAnswered = false,
    ) {
    }

    public function rule(DurationShape $shape): null|DurationReadingRule
    {
        return $this->durations[$shape->value]['rule'] ?? null;
    }

    /**
     * Only what was not worked out from the rows: the answers and the known export's settings - the start of
     * settling a (changed) mapping again.
     */
    public function keptAnswers(): self
    {
        $kept = static fn (null|array $setting): bool => $setting !== null
            && in_array($setting['by'], [self::BY_PLAYER, self::BY_KNOWN_EXPORT], true);

        return new self(
            array_filter($this->durations, $kept),
            $kept($this->dateOrder) ? $this->dateOrder : null,
            $kept($this->shortDates) ? $this->shortDates : null,
            $this->typedDatesSwapped,
            $kept($this->year) ? $this->year : null,
            $this->solvingType,
            $this->lookupSheetId,
            $this->collectionLabel,
            $this->partner,
            $this->solvingTypeAnswered,
        );
    }

    /**
     * Without anything a player said about times and dates - for a sheet the player never confirmed, whose answers
     * can only have come from a layout shared with it (docs/features/data-import/layouts.md "One workbook").
     */
    public function withoutPlayerAnswers(): self
    {
        $kept = static fn (null|array $setting): bool => $setting !== null && $setting['by'] !== self::BY_PLAYER;

        return new self(
            array_filter($this->durations, $kept),
            $kept($this->dateOrder) ? $this->dateOrder : null,
            $kept($this->shortDates) ? $this->shortDates : null,
            null,
            $kept($this->year) ? $this->year : null,
            $this->solvingType,
            $this->lookupSheetId,
            $this->collectionLabel,
        );
    }

    public function withDuration(DurationShape $shape, DurationReadingRule $rule, string $by): self
    {
        return $this->with(['durations' => [...$this->durations, $shape->value => ['rule' => $rule, 'by' => $by]]]);
    }

    public function withDateOrder(DateOrder $order, string $by): self
    {
        return $this->with(['dateOrder' => ['order' => $order, 'by' => $by]]);
    }

    public function withShortDates(ShortDateMeaning $meaning, string $by): self
    {
        return $this->with(['shortDates' => ['meaning' => $meaning, 'by' => $by]]);
    }

    public function withTypedDatesSwapped(bool $swapped): self
    {
        return $this->with(['typedDatesSwapped' => $swapped]);
    }

    public function withYear(int|string $year, string $by): self
    {
        return $this->with(['year' => ['year' => $year, 'by' => $by]]);
    }

    public function withLookupSheet(null|string $sheetId): self
    {
        return $this->with(['lookupSheetId' => $sheetId]);
    }

    public function withSolvingType(null|PuzzlingType $type): self
    {
        return $this->with(['solvingType' => $type]);
    }

    public function withPartner(null|string $partner): self
    {
        return $this->with(['partner' => $partner === null || trim($partner) === '' ? null : mb_substr(trim($partner), 0, 200)]);
    }

    public function withSolvingTypeAnswered(): self
    {
        return $this->with(['solvingTypeAnswered' => true]);
    }

    public function withCollectionLabel(null|string $label): self
    {
        return $this->with(['collectionLabel' => $label]);
    }

    /**
     * @param array<string, mixed> $changed
     */
    private function with(array $changed): self
    {
        /** @var array{durations: array<string, array{rule: DurationReadingRule, by: string}>, dateOrder: null|array{order: DateOrder, by: string}, shortDates: null|array{meaning: ShortDateMeaning, by: string}, typedDatesSwapped: null|bool, year: null|array{year: int|string, by: string}, solvingType: null|PuzzlingType, lookupSheetId: null|string, collectionLabel: null|string, partner: null|string, solvingTypeAnswered: bool} $all */
        $all = [...get_object_vars($this), ...$changed];

        return new self(...$all);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'durations' => array_map(
                static fn (array $setting): array => ['rule' => $setting['rule']->value, 'by' => $setting['by']],
                $this->durations,
            ),
            'dateOrder' => $this->dateOrder === null ? null : ['order' => $this->dateOrder['order']->value, 'by' => $this->dateOrder['by']],
            'shortDates' => $this->shortDates === null ? null : ['meaning' => $this->shortDates['meaning']->value, 'by' => $this->shortDates['by']],
            'typedDatesSwapped' => $this->typedDatesSwapped,
            'year' => $this->year,
            'solvingType' => $this->solvingType?->value,
            'lookupSheetId' => $this->lookupSheetId,
            'collectionLabel' => $this->collectionLabel,
            'partner' => $this->partner,
            'solvingTypeAnswered' => $this->solvingTypeAnswered,
        ];
    }

    /**
     * @param null|array<mixed> $data
     *
     * @throws InvalidImportMapping
     */
    public static function fromArray(null|array $data): self
    {
        if ($data === null || $data === []) {
            return new self();
        }

        $durations = [];
        $stored = $data['durations'] ?? [];

        if (is_array($stored) === false) {
            throw new InvalidImportMapping('"durations" must be an object.');
        }

        foreach ($stored as $shape => $setting) {
            if (is_string($shape) === false || DurationShape::tryFrom($shape) === null || is_array($setting) === false) {
                throw new InvalidImportMapping('"durations" names a shape we do not know.');
            }

            $durations[$shape] = ['rule' => StoredShape::enum($setting, 'rule', DurationReadingRule::class), 'by' => self::by($setting)];
        }

        $dateOrder = $data['dateOrder'] ?? null;
        $shortDates = $data['shortDates'] ?? null;
        $year = $data['year'] ?? null;
        $typedDatesSwapped = $data['typedDatesSwapped'] ?? null;

        if (($dateOrder !== null && is_array($dateOrder) === false) || ($shortDates !== null && is_array($shortDates) === false) || ($year !== null && is_array($year) === false) || ($typedDatesSwapped !== null && is_bool($typedDatesSwapped) === false)) {
            throw new InvalidImportMapping('The options are not the shape they must be.');
        }

        $yearValue = $year['year'] ?? null;

        if ($year !== null && $yearValue !== ImportQuestion::YEAR_AS_STORED && (is_int($yearValue) === false || $yearValue < 1950 || $yearValue > 2100)) {
            throw new InvalidImportMapping('The year must be a year from 1950 to 2100.');
        }

        $lookupSheetId = StoredShape::nullableString($data, 'lookupSheetId', 36);

        if ($lookupSheetId !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $lookupSheetId) !== 1) {
            throw new InvalidImportMapping('The sheet to look puzzles up in is named by its id.');
        }

        return new self(
            $durations,
            $dateOrder === null ? null : ['order' => StoredShape::enum($dateOrder, 'order', DateOrder::class), 'by' => self::by($dateOrder)],
            $shortDates === null ? null : ['meaning' => StoredShape::enum($shortDates, 'meaning', ShortDateMeaning::class), 'by' => self::by($shortDates)],
            $typedDatesSwapped,
            $year === null ? null : ['year' => $yearValue, 'by' => self::by($year)],
            StoredShape::nullableEnum($data, 'solvingType', PuzzlingType::class),
            $lookupSheetId,
            StoredShape::nullableString($data, 'collectionLabel', 200),
            StoredShape::nullableString($data, 'partner', 200),
            ($data['solvingTypeAnswered'] ?? false) === true,
        );
    }

    /**
     * @param array<mixed> $setting
     */
    private static function by(array $setting): string
    {
        $by = $setting['by'] ?? null;

        if (in_array($by, self::BY, true) === false) {
            throw new InvalidImportMapping('A setting must say who settled it.');
        }

        return $by;
    }
}
