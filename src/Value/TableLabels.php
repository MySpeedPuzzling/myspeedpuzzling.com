<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Exceptions\InvalidTableLabels;

/**
 * The names of a round's table numbers (docs/features/competitions-management/table-labels-and-plates.md): item i
 * names table number i + 1 - ["A1-1", "A1-2", "A2-1", …] for a hall plan. The number stays the entry's identity, sort
 * key and wire format everywhere; a label is only shown and typed. JS twin: assets/table_labels.js (shared cases
 * tests/fixtures/table-labels-cases.json - change both).
 *
 * The folded key compares what people type with the labels: letter runs and digit runs are segments, spaces, `-`, `.`
 * and `/` only separate them, letters upper-cased, digit runs without leading zeros - "A1-1", "a1 1" → `A.1.1`,
 * "A1L" / "A1 L" → `A.1.L`. Two labels with one key are duplicates.
 */
readonly final class TableLabels
{
    public const int MAX_LENGTH = 12;
    public const int MAX_COUNT = 9999;
    private const string LABEL_PATTERN = '/^[A-Za-z0-9](?:[ .\-\/]?[A-Za-z0-9])*$/';

    /** @var array<string, int> folded key => table number */
    private array $numbers;

    /**
     * @param list<string> $labels
     */
    private function __construct(
        public array $labels,
    ) {
        $numbers = [];

        foreach ($labels as $index => $label) {
            $numbers[self::key($label)] ??= $index + 1;
        }

        $this->numbers = $numbers;
    }

    /**
     * A stored list (CompetitionRound::$tableLabels, a query's jsonb column - a JSON string or the decoded list) as it
     * is - it was validated when written. Null (or an empty list) = plain numbers.
     *
     * @param null|string|array<mixed> $stored
     */
    public static function fromStored(null|string|array $stored): null|self
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (!is_array($stored) || $stored === []) {
            return null;
        }

        $labels = [];

        foreach ($stored as $label) {
            $labels[] = is_scalar($label) ? (string) $label : '';
        }

        return new self($labels);
    }

    /**
     * The organiser's list, validated: each label normalised (trimmed, inner whitespace one space), 1..12 characters of
     * ASCII letters, digits, space, `-`, `.`, `/` starting and ending with a letter or digit; at most 9999; folded keys unique.
     *
     * @param list<string> $labels
     * @throws InvalidTableLabels
     */
    public static function fromList(array $labels): self
    {
        if ($labels === []) {
            throw new InvalidTableLabels([['label' => '', 'reason' => 'empty_list']]);
        }

        if (count($labels) > self::MAX_COUNT) {
            throw new InvalidTableLabels([['label' => '', 'reason' => 'too_many']]);
        }

        $problems = [];
        $seen = [];
        $normalised = [];

        foreach ($labels as $label) {
            $label = self::normalise($label);
            $normalised[] = $label;
            $reason = self::problemOf($label);

            if ($reason !== null) {
                $problems[] = ['label' => $label, 'reason' => $reason];
                continue;
            }

            $key = self::key($label);

            if (isset($seen[$key])) {
                $problems[] = ['label' => $label, 'reason' => 'duplicate', 'first' => $seen[$key]];
                continue;
            }

            $seen[$key] = $label;
        }

        if ($problems !== []) {
            throw new InvalidTableLabels(array_slice($problems, 0, 20));
        }

        return new self($normalised);
    }

    /**
     * Why one (normalised) label cannot be used - empty, too_long, characters - or null.
     */
    public static function problemOf(string $label): null|string
    {
        if ($label === '') {
            return 'empty';
        }

        if (mb_strlen($label) > self::MAX_LENGTH) {
            return 'too_long';
        }

        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            return 'characters';
        }

        return null;
    }

    public static function normalise(string $label): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $label));
    }

    /**
     * The folded key of a label or of typed text ("a1 1" → `A.1.1`, "B07" → `B.7`); '' for text without a letter or
     * digit.
     */
    public static function key(string $text): string
    {
        preg_match_all('/[A-Za-z]+|[0-9]+/', $text, $matches);
        $segments = [];

        foreach ($matches[0] as $segment) {
            if (preg_match('/^[0-9]+$/', $segment) === 1) {
                $trimmed = ltrim($segment, '0');
                $segments[] = $trimmed === '' ? '0' : $trimmed;
            } else {
                $segments[] = strtoupper($segment);
            }
        }

        return implode('.', $segments);
    }

    public function count(): int
    {
        return count($this->labels);
    }

    /**
     * The name of a table number - the number itself beyond the list (never blank).
     */
    public function labelOf(int $number): string
    {
        $label = $this->labels[$number - 1] ?? '';

        return $label === '' ? (string) $number : $label;
    }

    /**
     * The table number whose label has the typed text's folded key, null when none has.
     */
    public function numberOf(string $typed): null|int
    {
        $key = self::key($typed);

        return $key === '' ? null : ($this->numbers[$key] ?? null);
    }

    /**
     * Whether the list names this table number - a labelled round takes no number beyond its list.
     */
    public function names(int $number): bool
    {
        return $number >= 1 && $number <= count($this->labels);
    }

    /**
     * What a table number reads as - its label on a labelled round, else the number; null stays null.
     */
    public static function display(null|self $labels, null|int $number): null|string
    {
        if ($number === null) {
            return null;
        }

        return $labels === null ? (string) $number : $labels->labelOf($number);
    }
}
