<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use SpeedPuzzling\Web\Query\GetResultsImportSources;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Results import" of an organization (docs/features/event-results-import/results-import-page.md "Pages"): its results
 * sources - one leads straight to its page, several are listed, none = 404 (admins set them up, P1).
 */
final class ResultsImportSourcesController extends AbstractController
{
    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private GetResultsImportSources $getResultsImportSources,
        readonly private ResultsFormats $formats,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import',
        name: 'results_import_sources',
        methods: ['GET'],
    )]
    public function __invoke(string $slug): Response
    {
        $organization = $this->resultsImportPage->organization($slug);
        $sources = $this->getResultsImportSources->ofOrganization($organization->id);

        if ($sources === []) {
            throw new NotFoundHttpException();
        }

        if (count($sources) === 1) {
            return $this->redirectToRoute('results_import', ['slug' => $organization->slug, 'sourceId' => $sources[0]->id]);
        }

        $formatNames = [];

        foreach ($sources as $source) {
            $formatNames[$source->id] = $this->formats->has($source->format) ? $this->formats->get($source->format)->name() : $source->format;
        }

        $response = $this->render('results_import/sources.html.twig', [
            'organization' => $organization,
            'sources' => $sources,
            'format_names' => $formatNames,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
