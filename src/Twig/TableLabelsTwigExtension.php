<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\TableLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ entry.tableNumber|table_name(labels) }}` - a table number as its round names it (table-labels-and-plates.md):
 * the label on a round with table labels, else the number; null stays null (`?? '–'` keeps working).
 */
final class TableLabelsTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('table_name', static fn (null|int $number, null|TableLabels $labels = null): null|string => TableLabels::display($labels, $number)),
        ];
    }
}
