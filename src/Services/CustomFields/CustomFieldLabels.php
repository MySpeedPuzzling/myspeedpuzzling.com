<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\CustomValues;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a player's own field and its values are worded (docs/features/custom-fields.md): a ready-made field's name and
 * choices follow the page language, a player's own field shows what they typed.
 */
readonly final class CustomFieldLabels
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function label(CustomFieldOverview $field): string
    {
        return $field->standard !== null ? $this->translator->trans($field->standard->labelKey()) : $field->name;
    }

    public function choiceLabel(CustomFieldOverview $field, string $choice): string
    {
        // A value the ready-made list no longer has is shown as it is stored
        if ($field->standard !== null && in_array($choice, $field->standard->choices(), true)) {
            return $this->translator->trans($field->standard->choiceLabelKey($choice));
        }

        return $choice;
    }

    /**
     * A stored value as the owner reads it.
     *
     * @param null|string|int|float|bool|list<string> $value
     */
    public function valueText(CustomFieldOverview $field, null|string|int|float|bool|array $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $this->translator->trans($value ? 'my_fields.value.yes' : 'my_fields.value.no');
        }

        if ($field->type === CustomFieldType::Choice && is_string($value)) {
            return $this->choiceLabel($field, $value);
        }

        if ($field->type === CustomFieldType::Date && is_string($value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false ? $date->format('d.m.Y') : $value;
        }

        return CustomValues::toInput($value);
    }
}
