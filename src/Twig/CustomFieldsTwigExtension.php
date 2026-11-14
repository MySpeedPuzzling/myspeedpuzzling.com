<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Services\CustomFields\CustomFieldLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A player's own fields in their own pages (docs/features/custom-fields.md): `my_field_label(field)` (a
 * ready-made field in the page language, the player's own as typed), `my_field_choice_label(field, choice)` and
 * `my_field_value_text(field, value)` (a stored value as the owner reads it).
 */
final class CustomFieldsTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private CustomFieldLabels $customFieldLabels,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('my_field_label', $this->label(...)),
            new TwigFunction('my_field_choice_label', $this->choiceLabel(...)),
            new TwigFunction('my_field_value_text', $this->valueText(...)),
        ];
    }

    public function label(CustomFieldOverview $field): string
    {
        return $this->customFieldLabels->label($field);
    }

    public function choiceLabel(CustomFieldOverview $field, string $choice): string
    {
        return $this->customFieldLabels->choiceLabel($field, $choice);
    }

    /**
     * @param null|string|int|float|bool|list<string> $value
     */
    public function valueText(CustomFieldOverview $field, null|string|int|float|bool|array $value): string
    {
        return $this->customFieldLabels->valueText($field, $value);
    }
}
