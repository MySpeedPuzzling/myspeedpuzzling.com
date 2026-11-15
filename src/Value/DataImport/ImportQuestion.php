<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Value\DataImportLimits;

/**
 * One thing a sheet asks the player before its rows can be parsed (docs/features/data-import/columns.md
 * "Questions"). While a sheet has one, it waits.
 */
final readonly class ImportQuestion
{
    /** The answer "as the cells store it" of the year question */
    public const string YEAR_AS_STORED = 'stored';

    /**
     * @param list<string> $choices what the player may answer (a DurationReadingRule, a DateOrder, a
     *        ShortDateMeaning, "yes" / "no", a year or YEAR_AS_STORED); the year question also takes any year
     * @param list<string> $examples up to three values of the column the answers read differently, as shown
     */
    public function __construct(
        public ImportQuestionType $type,
        public int $column,
        public array $choices,
        public null|string $suggested = null,
        public array $examples = [],
        /** The shape of the time column the question is about (ImportQuestionType::DurationShape) */
        public null|DurationShape $shape = null,
        /** How many rows the answer decides */
        public int $rows = 0,
    ) {
    }

    /**
     * What an answer names the question by: stable for the same question of the same column.
     */
    public function id(): string
    {
        return $this->type->value . ($this->shape === null ? '' : '.' . $this->shape->value);
    }

    public function accepts(string $answer): bool
    {
        if (in_array($answer, $this->choices, true)) {
            return true;
        }

        return $this->type === ImportQuestionType::Year
            && preg_match('/^(19[5-9]\d|20\d\d|2100)$/', $answer) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => $this->type->value,
            'column' => $this->column,
            'shape' => $this->shape?->value,
            'choices' => $this->choices,
            'suggested' => $this->suggested,
            'examples' => $this->examples,
            'rows' => $this->rows,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            StoredShape::enum($data, 'type', ImportQuestionType::class),
            StoredShape::int($data, 'column', 1, DataImportLimits::MAX_COLUMNS),
            self::texts($data, 'choices'),
            StoredShape::nullableString($data, 'suggested', 40),
            self::texts($data, 'examples'),
            StoredShape::nullableEnum($data, 'shape', DurationShape::class),
            StoredShape::int($data, 'rows'),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function texts(array $data, string $key): array
    {
        $texts = $data[$key] ?? [];

        if (is_array($texts) === false || count($texts) > 20) {
            throw new InvalidImportMapping(sprintf('"%s" must be a short list of texts.', $key));
        }

        $list = [];

        foreach ($texts as $text) {
            if (is_string($text) === false || mb_strlen($text) > 300) {
                throw new InvalidImportMapping(sprintf('"%s" must be a short list of texts.', $key));
            }

            $list[] = $text;
        }

        return $list;
    }
}
