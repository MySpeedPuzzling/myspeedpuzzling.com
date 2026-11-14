<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use SpeedPuzzling\Web\Query\GetCollectionItemCustomValues;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Query\GetCustomValues;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Results\Export\OwnCustomColumns;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomValues;

/**
 * The signed-in player's own fields and values for their own export (docs/features/data-export.md "Own fields").
 *
 * **Whose values: the signed-in player's, resolved here** - there is no player id to pass, so the export of a
 * player can only ever carry the downloading player's own. A guest gets nothing.
 *
 * The columns: the fields in use for the place (results / collection puzzles) in the player's order - also while
 * empty, so the file's shape does not depend on what is filled in - then every other field that still holds a value
 * there (archived, or no longer for that place).
 *
 * Two statements whatever the size of the export (the fields, the values); one for a player without any field.
 */
readonly final class OwnCustomValuesInExport
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private GetCustomFields $getCustomFields,
        private GetCustomValues $getCustomValues,
        private GetCollectionItemCustomValues $getCollectionItemCustomValues,
    ) {
    }

    /**
     * Keyed by solving time id.
     */
    public function onResultsOfSignedInViewer(): OwnCustomColumns
    {
        return $this->columns(
            static fn (CustomFieldOverview $field): bool => $field->isUsableOnResults(),
            fn (string $viewerId): array => $this->getCustomValues->allOfViewer($viewerId),
        );
    }

    /**
     * Keyed by collection item id.
     */
    public function onCollectionItemsOfSignedInViewer(): OwnCustomColumns
    {
        return $this->columns(
            static fn (CustomFieldOverview $field): bool => $field->isUsableOnCollectionItems(),
            fn (string $viewerId): array => $this->getCollectionItemCustomValues->allOfViewer($viewerId),
        );
    }

    /**
     * @param callable(CustomFieldOverview): bool $inUseHere
     * @param callable(string): array<string, CustomValues> $valuesOf
     */
    private function columns(callable $inUseHere, callable $valuesOf): OwnCustomColumns
    {
        $viewerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

        if ($viewerId === null) {
            return new OwnCustomColumns();
        }

        $fields = $this->getCustomFields->ofPlayer($viewerId);

        if ($fields === []) {
            return new OwnCustomColumns();
        }

        $values = $valuesOf($viewerId);
        $held = [];

        foreach ($values as $rowValues) {
            $held += $rowValues->values;
        }

        /** @var array<string, string> $labels field id => label */
        $labels = [];

        foreach ($fields as $field) {
            if ($inUseHere($field)) {
                $labels[$field->fieldId] = self::label($field);
            }
        }

        foreach ($fields as $field) {
            if (array_key_exists($field->fieldId, $held)) {
                $labels[$field->fieldId] ??= self::label($field);
            }
        }

        $valuesByRow = [];

        foreach ($values as $rowId => $rowValues) {
            foreach ($labels as $fieldId => $label) {
                $value = $rowValues->of($fieldId);

                if ($value !== null) {
                    $valuesByRow[$rowId][$label] = $value;
                }
            }
        }

        return new OwnCustomColumns(array_values($labels), $valuesByRow);
    }

    /**
     * A ready-made field by its fixed key (the export's column names are English and the same in every language), a
     * player's own field by the name they gave it.
     */
    private static function label(CustomFieldOverview $field): string
    {
        return $field->standard !== null
            ? OwnCustomColumns::STANDARD_PREFIX . $field->standard->value
            : OwnCustomColumns::OWN_PREFIX . $field->name;
    }
}
