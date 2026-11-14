<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * One of the signed-in player's own fields, for their own pages (docs/features/custom-fields.md). Never part of
 * anything another player is shown.
 */
readonly final class CustomFieldOverview
{
    /**
     * @param list<string> $choices
     */
    public function __construct(
        public string $fieldId,
        // What the player typed; for a ready-made field its English name - shown through `my_field_label()`
        public string $name,
        public null|StandardCustomField $standard,
        public CustomFieldType $type,
        public array $choices,
        public bool $forResults,
        public bool $forCollectionItems,
        public bool $archived,
    ) {
    }

    public function isUsableOnResults(): bool
    {
        return $this->forResults && $this->archived === false;
    }

    public function isUsableOnCollectionItems(): bool
    {
        return $this->forCollectionItems && $this->archived === false;
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     standard_key: null|string,
     *     type: string,
     *     choices: null|string,
     *     for_results: bool,
     *     for_collection_items: bool,
     *     archived: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $choices = json_decode($row['choices'] ?? '[]', true);

        return new self(
            fieldId: $row['id'],
            name: $row['name'],
            standard: $row['standard_key'] !== null ? StandardCustomField::tryFrom($row['standard_key']) : null,
            type: CustomFieldType::from($row['type']),
            choices: is_array($choices) ? array_values(array_filter($choices, is_string(...))) : [],
            forResults: $row['for_results'],
            forCollectionItems: $row['for_collection_items'],
            archived: $row['archived'],
        );
    }
}
