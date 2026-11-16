<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportComparison;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The results import page of one results source (docs/features/event-results-import/results-import-page.md): "Check
 * sheet", then every event of the latest check with its status against what is stored, the tabs Google did not give
 * us, and the format's guide. Nothing is read from Google here - only the stored check.
 */
final class ResultsImportController extends AbstractController
{
    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private ResultsImportCheckRepository $checkRepository,
        readonly private ResultsImportComparison $comparison,
        readonly private ResultsFormats $formats,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import/{sourceId}',
        name: 'results_import',
        methods: ['GET'],
    )]
    public function __invoke(string $slug, string $sourceId): Response
    {
        $organization = $this->resultsImportPage->organization($slug);
        $source = $this->resultsImportPage->sourceOf($organization, $sourceId);
        $check = $this->checkRepository->latestOf($source);

        $response = $this->render('results_import/page.html.twig', [
            'organization' => $organization,
            'source_id' => $source->id->toString(),
            'format' => $this->formats->get($source->format),
            'overview' => $check !== null ? $this->comparison->compare($source, $check) : null,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
