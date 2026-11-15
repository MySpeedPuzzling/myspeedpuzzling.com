<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\ResultDateFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `result_date(date, precision, style = 'numeric')` - the date of a result, as much of it as is known: "15.03.2021" /
 * "Mar 2021" / "2021" (`numeric`, the compact lists) or "15 Mar 2021" / "March 2021" / "2021" (`medium`), in the page's
 * locale (`locale:` for an e-mail). `result_date_machine(date, precision)` - the same for `<time datetime>`.
 * Every template that prints when a result was solved goes through it (docs/features/data-import/date-precision.md).
 */
final class ResultDateTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private ResultDateFormatter $resultDateFormatter,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('result_date', $this->resultDateFormatter->format(...)),
            new TwigFunction('result_date_machine', ResultDateFormatter::machine(...)),
        ];
    }
}
