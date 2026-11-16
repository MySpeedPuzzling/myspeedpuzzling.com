<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use SpeedPuzzling\Web\Entity\ResultsImportLog;
use SpeedPuzzling\Web\Query\GetResultsImportEventLinks;
use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use SpeedPuzzling\Web\Repository\ResultsImportLogRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportComparison;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The results import page of one results source (docs/features/event-results-import/results-import-page.md): "Check
 * sheet", then every event of the latest check with its status against what is stored - New and Changed ones ticked
 * for "Import" -, the tabs Google did not give us, the result of an import just made (`?import=`, with links to each
 * event's results), the latest imports and the format's guide. Nothing is read from Google here - only the stored check.
 */
final class ResultsImportController extends AbstractController
{
    private const int HISTORY = 10;

    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private ResultsImportCheckRepository $checkRepository,
        readonly private ResultsImportLogRepository $logRepository,
        readonly private ResultsImportComparison $comparison,
        readonly private ResultsFormats $formats,
        readonly private GetResultsImportEventLinks $getEventLinks,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import/{sourceId}',
        name: 'results_import',
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $slug, string $sourceId): Response
    {
        $organization = $this->resultsImportPage->organization($slug);
        $source = $this->resultsImportPage->sourceOf($organization, $sourceId);
        $check = $this->checkRepository->latestOf($source);

        $history = $this->logRepository->latestOf($source, self::HISTORY);
        $justImported = null;
        $importId = $request->query->getString('import');

        if ($importId !== '') {
            $justImported = $this->logRepository->find($importId);

            if ($justImported !== null && $justImported->source->id->equals($source->id) === false) {
                $justImported = null;
            }
        }

        $competitionIds = [];

        foreach ($justImported !== null ? [$justImported] : [] as $log) {
            foreach ($log->events as $event) {
                if ($event['competition_id'] !== null) {
                    $competitionIds[] = $event['competition_id'];
                }
            }
        }

        $response = $this->render('results_import/page.html.twig', [
            'organization' => $organization,
            'source_id' => $source->id->toString(),
            'format' => $this->formats->get($source->format),
            'overview' => $check !== null ? $this->comparison->compare($source, $check) : null,
            'history' => array_values(array_filter($history, static fn (ResultsImportLog $log): bool => $log !== $justImported)),
            'just_imported' => $justImported,
            'event_links' => $this->getEventLinks->byCompetitionIds($competitionIds),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
