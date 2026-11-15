<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use SpeedPuzzling\Web\Value\DatePrecision;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * THE date of a result, as much of it as is known (docs/features/data-import/date-precision.md "Showing"): a day, or
 * only the month or the year for a result whose file knew no more. A result known to its month is stored on the
 * first day of that month; nothing may print that day.
 *
 * Two looks, in the page's locale:
 *
 * | Style     | A day                                              | A month      | A year |
 * |-----------|----------------------------------------------------|--------------|--------|
 * | `medium`  | ICU's medium date, what `|format_date('medium')`   | "March 2021" | "2021" |
 * |           | prints: "15 Mar 2021" (en-GB), "Mar 15, 2021" (en) |              |        |
 * | `numeric` | "15.03.2021" - what `|date('d.m.Y')` prints in the | "Mar 2021"   | "2021" |
 * |           | compact lists and leaderboards                     |              |        |
 *
 * **A day is written exactly as the templates wrote it before precision existed** - the same pattern, the same zone
 * (Twig's `date.timezone`), the same locale: nothing changes for a result that knows its day. The month and the year
 * come from ICU skeletons (`yMMMM`, `yMMM`, `y`), so each language puts and words them its own way ("březen 2021",
 * "2021年3月", "2021年"); they are read from the stored date as it is, never moved into another zone.
 *
 * Twig: `result_date(date, precision, style)` (ResultDateTwigExtension) - the only way a template prints the date of
 * a result.
 */
final class ResultDateFormatter implements ResetInterface
{
    public const string STYLE_MEDIUM = 'medium';
    public const string STYLE_NUMERIC = 'numeric';

    /** Twig's `date.timezone` (config/packages/twig.php): the zone every `|date` and `|format_date` of the site writes in */
    public const string DISPLAY_TIMEZONE = 'Europe/Prague';

    /** @var array<string, IntlDateFormatter> locale|zone|pattern => formatter */
    private array $formatters = [];

    /** @var array<string, string> locale|skeleton => pattern */
    private array $patterns = [];

    public function __construct(
        readonly private TranslatorInterface $translator,
    ) {
    }

    public function format(
        DateTimeInterface $date,
        null|DatePrecision|string $precision = null,
        string $style = self::STYLE_NUMERIC,
        null|string $locale = null,
    ): string {
        $precision = $precision instanceof DatePrecision ? $precision : DatePrecision::ofStored($precision);
        $locale = str_replace('-', '_', $locale ?? $this->translator->getLocale());

        if ($precision === DatePrecision::Day) {
            $shown = DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone(self::DISPLAY_TIMEZONE));

            if ($style === self::STYLE_NUMERIC) {
                return $shown->format('d.m.Y');
            }

            $formatted = $this->formatter($locale, self::DISPLAY_TIMEZONE, null)->format($shown);

            return is_string($formatted) && $formatted !== '' ? $formatted : $shown->format('Y-m-d');
        }

        $skeleton = match (true) {
            $precision === DatePrecision::Year => 'y',
            $style === self::STYLE_NUMERIC => 'yMMM',
            default => 'yMMMM',
        };
        // The first day of the period as stored - its own year and month, whatever zone anything is shown in
        $anchor = new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
        $formatted = $this->formatter($locale, 'UTC', $this->pattern($skeleton, $locale))->format($anchor);

        return is_string($formatted) && $formatted !== '' ? $formatted : self::machine($date, $precision);
    }

    /**
     * For machines (`<time datetime>`): `2021-03-15`, `2021-03`, `2021` - all valid HTML date strings.
     */
    public static function machine(DateTimeInterface $date, null|DatePrecision|string $precision = null): string
    {
        $precision = $precision instanceof DatePrecision ? $precision : DatePrecision::ofStored($precision);

        return match ($precision) {
            DatePrecision::Day => DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone(self::DISPLAY_TIMEZONE))->format('Y-m-d'),
            DatePrecision::Month => $date->format('Y-m'),
            DatePrecision::Year => $date->format('Y'),
        };
    }

    public function reset(): void
    {
        $this->formatters = [];
        $this->patterns = [];
    }

    private function pattern(string $skeleton, string $locale): string
    {
        if (isset($this->patterns[$locale . '|' . $skeleton]) === false) {
            $pattern = new IntlDatePatternGenerator($locale)->getBestPattern($skeleton);
            $this->patterns[$locale . '|' . $skeleton] = is_string($pattern) && $pattern !== '' ? $pattern : $skeleton;
        }

        return $this->patterns[$locale . '|' . $skeleton];
    }

    private function formatter(string $locale, string $zone, null|string $pattern): IntlDateFormatter
    {
        return $this->formatters[$locale . '|' . $zone . '|' . ($pattern ?? '')] ??= new IntlDateFormatter(
            $locale,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE,
            $zone,
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );
    }
}
