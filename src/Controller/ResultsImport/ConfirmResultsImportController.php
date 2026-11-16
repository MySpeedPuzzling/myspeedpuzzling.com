<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportEvent;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportComparison;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Import 3 events, 37 teams?" (docs/features/event-results-import/results-import-page.md "As built: PR 3"): the ticked
 * events of the latest check that can be imported (New, Changed), what each adds or changes - the organizer's approval
 * (P2), there is no undo (P3). A GET - nothing changes here; the form carries a new import id, so sending it twice
 * imports nothing twice. Nothing ticked, or an older check than the latest: back to the page with a word why.
 */
final class ConfirmResultsImportController extends AbstractController
{
    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private ResultsImportCheckRepository $checkRepository,
        readonly private ResultsImportComparison $comparison,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import/{sourceId}/import',
        name: 'results_import_confirm',
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $slug, string $sourceId): Response
    {
        $organization = $this->resultsImportPage->organization($slug);
        $source = $this->resultsImportPage->sourceOf($organization, $sourceId);
        $back = $this->redirectToRoute('results_import', ['slug' => $organization->slug, 'sourceId' => $source->id->toString()]);

        $latest = $this->checkRepository->latestOf($source);

        if ($latest === null || $latest->id->toString() !== strtolower($request->query->getString('check'))) {
            $this->addFlash('warning', $this->translator->trans('results_import.flash.newer_check'));

            return $back;
        }

        $keys = array_values(array_filter($request->query->all('events'), is_string(...)));
        $overview = $this->comparison->compare($source, $latest);
        $events = array_values(array_filter(
            $overview->events,
            static fn (ResultsImportEvent $event): bool => $event->status->canBeImported() && in_array($event->event->key, $keys, true),
        ));

        if ($events === []) {
            $this->addFlash('warning', $this->translator->trans('results_import.flash.nothing_ticked'));

            return $back;
        }

        $response = $this->render('results_import/confirm.html.twig', [
            'organization' => $organization,
            'source_id' => $source->id->toString(),
            'overview' => $overview,
            'events' => $events,
            'team_count' => array_sum(array_map(static fn (ResultsImportEvent $event): int => $event->teamCount(), $events)),
            'import_id' => Uuid::uuid7()->toString(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
