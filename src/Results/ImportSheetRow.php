<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A row of a sheet as the import understood it (GetImportSheetScreen::understoodRows(); the shape of `parsed` is
 * docs/features/data-import/columns.md "What a parsed row holds"). Everything in it is the player's own text:
 * shown escaped, never put in a URL.
 */
readonly final class ImportSheetRow
{
    /**
     * @param array<string, mixed> $parsed
     * @param list<array{field: string, code: string, value: string, waiting: bool}> $problems
     */
    public function __construct(
        /** The spreadsheet's own row number */
        public int $rowNumber,
        /** A DataImportRowKind's value */
        public string $kind,
        public array $parsed,
        public array $problems,
    ) {
    }

    public function text(string $key): null|string
    {
        $value = $this->parsed[$key] ?? null;

        return match (true) {
            is_string($value) && $value !== '' => $value,
            is_int($value) => (string) $value,
            default => null,
        };
    }

    public function pieces(): null|int
    {
        return is_int($this->parsed['pieces'] ?? null) ? $this->parsed['pieces'] : null;
    }

    /**
     * The solving time as `h:mm:ss` - null when the row has none.
     */
    public function time(): null|string
    {
        $seconds = $this->parsed['seconds'] ?? null;

        if (is_int($seconds) === false || $seconds < 0) {
            return null;
        }

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * The day a result was solved on - null unless the row knows the very day (a month or a year is date()'s text).
     */
    public function day(): null|\DateTimeImmutable
    {
        $date = $this->text('date');

        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: null;
    }

    /**
     * @return list<string>
     */
    public function people(): array
    {
        $people = $this->parsed['people'] ?? [];

        return is_array($people) ? array_values(array_filter($people, is_string(...))) : [];
    }
}
