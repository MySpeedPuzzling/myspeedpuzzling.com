<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Exceptions\InvalidCustomFieldDefinition;

/**
 * How a player set up one of their own fields, cleaned and checked (docs/features/custom-fields.md) - the one rule
 * for adding and editing a field: a name of 1-60 characters (valid UTF-8, no control characters), choices only for "one of a list" (1-30, each at most 50
 * characters, each once whatever its letter case), and at least one place the field is for.
 */
readonly final class CustomFieldDefinition
{
    public const int NAME_MAX_LENGTH = 60;
    public const int MAX_CHOICES = 30;
    public const int CHOICE_MAX_LENGTH = 50;

    /**
     * @param list<string> $choices
     */
    private function __construct(
        public string $name,
        public CustomFieldType $type,
        public array $choices,
        public bool $forResults,
        public bool $forCollectionItems,
    ) {
    }

    /**
     * @param array<mixed> $choices
     *
     * @throws InvalidCustomFieldDefinition
     */
    public static function fromInput(string $name, CustomFieldType $type, array $choices, bool $forResults, bool $forCollectionItems): self
    {
        if (CustomValues::isStorableText($name, multiline: true) === false) {
            throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::INVALID_CHARACTERS);
        }

        $name = self::clean($name);

        if ($name === '') {
            throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::NAME_MISSING);
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::NAME_TOO_LONG);
        }

        if ($forResults === false && $forCollectionItems === false) {
            throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::NO_TARGET);
        }

        return new self($name, $type, $type === CustomFieldType::Choice ? self::cleanChoices($choices) : [], $forResults, $forCollectionItems);
    }

    /**
     * Two names are the same field name whatever their letter case.
     */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(self::clean($name));
    }

    /**
     * @param array<mixed> $choices
     * @return list<string>
     *
     * @throws InvalidCustomFieldDefinition
     */
    private static function cleanChoices(array $choices): array
    {
        $cleaned = [];

        foreach ($choices as $choice) {
            if (is_string($choice) && CustomValues::isStorableText($choice, multiline: true) === false) {
                throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::INVALID_CHARACTERS);
            }

            $choice = is_string($choice) ? self::clean($choice) : '';

            if ($choice === '') {
                continue;
            }

            if (mb_strlen($choice) > self::CHOICE_MAX_LENGTH) {
                throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::CHOICE_TOO_LONG);
            }

            $cleaned[mb_strtolower($choice)] ??= $choice;
        }

        if ($cleaned === []) {
            throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::CHOICES_MISSING);
        }

        if (count($cleaned) > self::MAX_CHOICES) {
            throw new InvalidCustomFieldDefinition(InvalidCustomFieldDefinition::TOO_MANY_CHOICES);
        }

        return array_values($cleaned);
    }

    /**
     * One line: every run of white space (a line break or tab too) is one space. Only for text isStorableText() took -
     * broken UTF-8 would come back from the regular expression as nothing.
     */
    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
