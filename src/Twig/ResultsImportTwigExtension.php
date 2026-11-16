<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `results_import_enabled()`: the links to the results import page are rendered only while its kill switch is on
 * (RESULTS_IMPORT_PAGE, docs/features/feature_flags.md) - no statement.
 */
final class ResultsImportTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('results_import_enabled', $this->resultsImportPage->isEnabled(...)),
        ];
    }
}
