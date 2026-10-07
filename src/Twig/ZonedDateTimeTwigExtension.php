<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `zoned_datetime(moment, timezone, assumed = false)` and `timezone_name(timezone, assumed = false)` - see
 * ZonedDateTimeFormatter (`assumed`: the read model's `timezoneAssumed`, RoundTimezone::isAssumed()).
 */
final class ZonedDateTimeTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ZonedDateTimeFormatter $formatter,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('zoned_datetime', $this->formatter->format(...)),
            new TwigFunction('timezone_name', $this->formatter->timezoneName(...)),
        ];
    }
}
