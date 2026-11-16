<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportComparison;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The preview of one event of a check (docs/features/event-results-import/results-import-page.md "Pages"): the event as
 * it will be created or updated, its teams ranked as its results page will rank them, what changes against what is
 * stored, its problems and warnings. Of the check named by `?check=` (the list links it), else of the latest one.
 */
final class ResultsImportEventController extends AbstractController
{
    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private ResultsImportCheckRepository $checkRepository,
        readonly private ResultsImportComparison $comparison,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import/{sourceId}/events/{eventKey}',
        name: 'results_import_event',
        requirements: ['eventKey' => '[A-Za-z0-9_-]{1,20}'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $slug, string $sourceId, string $eventKey): Response
    {
        $organization = $this->resultsImportPage->organization($slug);
        $source = $this->resultsImportPage->sourceOf($organization, $sourceId);

        $checkId = $request->query->getString('check');
        $check = $checkId !== '' ? $this->checkRepository->get($checkId) : $this->checkRepository->latestOf($source);

        if ($check === null || $check->source->id->equals($source->id) === false) {
            throw new NotFoundHttpException();
        }

        $overview = $this->comparison->compare($source, $check);
        $event = $overview->event($eventKey) ?? throw new NotFoundHttpException();

        $response = $this->render('results_import/event.html.twig', [
            'organization' => $organization,
            'source' => $source,
            'overview' => $overview,
            'item' => $event,
            'ranked' => ResultsImportComparison::rankedRows($event->event, $event->teamChanges),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
