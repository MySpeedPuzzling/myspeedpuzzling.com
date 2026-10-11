<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\PlainNumber;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ count|plain_number }}` - a number the MySpeedPuzzling way (PlainNumber): "4141", "10000", "1 234 567", never a
 * comma between the thousands. Use it instead of `format_number` for counts, XP, AP, ranks and totals.
 */
final class PlainNumberTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('plain_number', static fn (null|int|float|string $number, null|int $decimals = null): string => PlainNumber::format(is_numeric($number) ? $number + 0 : 0, null, $decimals)),
        ];
    }
}
