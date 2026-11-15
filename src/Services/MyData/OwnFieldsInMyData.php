<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MyData;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetCollectionItemCustomValues;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Query\GetCustomValues;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Results\MyData\MyDataOwnFields;
use SpeedPuzzling\Web\Services\CustomFields\CustomFieldLabels;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\CustomValues;
use SpeedPuzzling\Web\Value\MyDataColumns;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The player's own fields as columns of "My data" (docs/features/my-data.md "Own fields"): the fields in use for the
 * place (results / collection puzzles) in the player's order - archived ones are not columns -, and what the rows of
 * ONE page hold in them.
 *
 * **Whose: the signed-in player's, resolved here** - there is no player id to pass, so a caller can not hand out
 * somebody else's. A pair partner's values on a shared result are never read: both readers are bound to the viewer.
 *
 * Two statements per page whatever the number of rows and fields (the fields, the values of the page's rows); one
 * for a player without fields. Whenever anything goes wrong the table is shown without the columns - reading never
 * fails the page.
 */
readonly final class OwnFieldsInMyData
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private GetCustomFields $getCustomFields,
        private GetCustomValues $getCustomValues,
        private GetCollectionItemCustomValues $getCollectionItemCustomValues,
        private CustomFieldLabels $labels,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $solvingTimeIds the results of the page
     */
    public function onResultsOfSignedInViewer(array $solvingTimeIds): MyDataOwnFields
    {
        return $this->read(
            static fn (CustomFieldOverview $field): bool => $field->isUsableOnResults(),
            fn (string $viewerId): array => $this->getCustomValues->forViewer($solvingTimeIds, $viewerId),
        );
    }

    /**
     * @param list<string> $collectionItemIds the collection items of the page
     */
    public function onCollectionItemsOfSignedInViewer(array $collectionItemIds): MyDataOwnFields
    {
        return $this->read(
            static fn (CustomFieldOverview $field): bool => $field->isUsableOnCollectionItems(),
            fn (string $viewerId): array => $this->getCollectionItemCustomValues->forViewer($collectionItemIds, $viewerId),
        );
    }

    /**
     * How a cell of the field is edited in place on the wide table (docs/features/my-data.md "Editing in place") -
     * the inputs of `my_fields/_inputs.html.twig`, one line each.
     *
     * @return array{type: string, options: list<array{string, string}>}
     */
    private function editor(CustomFieldOverview $field): array
    {
        $none = ['', $this->translator->trans('my_fields.value.none')];

        return match ($field->type) {
            CustomFieldType::Number => ['type' => 'number', 'options' => []],
            CustomFieldType::Date => ['type' => 'date', 'options' => []],
            CustomFieldType::YesNo => ['type' => 'select', 'options' => [
                $none,
                ['yes', $this->translator->trans('my_fields.value.yes')],
                ['no', $this->translator->trans('my_fields.value.no')],
            ]],
            CustomFieldType::Choice => ['type' => 'select', 'options' => [
                $none,
                ...array_map(fn (string $choice): array => [$choice, $this->labels->choiceLabel($field, $choice)], $field->choices),
            ]],
            CustomFieldType::Rating => ['type' => 'select', 'options' => [
                ['', $this->translator->trans('my_fields.value.rating_none')],
                ...array_map(fn (int $stars): array => [(string) $stars, $this->translator->trans('my_fields.value.rating_stars', ['%stars%' => $stars])], range(1, 5)),
            ]],
            default => ['type' => 'text', 'options' => []],
        };
    }

    /**
     * @param callable(CustomFieldOverview): bool $inUse
     * @param callable(string): array<string, CustomValues> $valuesOfViewer
     */
    private function read(callable $inUse, callable $valuesOfViewer): MyDataOwnFields
    {
        try {
            $viewerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

            if ($viewerId === null) {
                return new MyDataOwnFields();
            }

            $fields = array_values(array_filter($this->getCustomFields->ofPlayer($viewerId), $inUse));

            if ($fields === []) {
                return new MyDataOwnFields();
            }

            $columns = [];
            $editors = [];

            foreach ($fields as $field) {
                $columns[MyDataColumns::fieldColumn($field->fieldId)] = $this->labels->label($field);
                $editors[MyDataColumns::fieldColumn($field->fieldId)] = $this->editor($field);
            }

            $cells = [];
            $inputs = [];

            foreach ($valuesOfViewer($viewerId) as $rowId => $values) {
                foreach ($fields as $field) {
                    $text = $this->labels->valueText($field, $values->of($field->fieldId));

                    if ($text !== '') {
                        $cells[$rowId][MyDataColumns::fieldColumn($field->fieldId)] = $text;
                        $inputs[$rowId][MyDataColumns::fieldColumn($field->fieldId)] = CustomValues::toInput($values->of($field->fieldId));
                    }
                }
            }

            return new MyDataOwnFields($columns, $cells, $inputs, $editors);
        } catch (\Throwable $exception) {
            // Reading carries no value of anybody (the statements' parameters are ids) - logged as it is
            $this->logger->warning('Custom fields could not be loaded for My data - the table is shown without them', [
                'exception' => $exception,
            ]);

            return new MyDataOwnFields();
        }
    }
}
