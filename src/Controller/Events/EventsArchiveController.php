<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One indexable page per year with past events (docs/features/events-page/README.md, "SEO") - public occurrences only,
 * one statement. A year without past events is not found.
 */
final class EventsArchiveController extends AbstractController
{
    public function __construct(
        readonly private GetEventOccurrences $getEventOccurrences,
        readonly private EventsPageBuilder $eventsPageBuilder,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/archiv/{year}',
            'en' => '/en/events/archive/{year}',
            'es' => '/es/eventos/archivo/{year}',
            'ja' => '/ja/イベント/アーカイブ/{year}',
            'fr' => '/fr/evenements/archives/{year}',
            'de' => '/de/veranstaltungen/archiv/{year}',
        ],
        name: 'events_archive',
        requirements: ['year' => '\d{4}'],
    )]
    public function __invoke(Request $request, int $year): Response
    {
        $archive = $this->eventsPageBuilder->buildArchive(
            $this->getEventOccurrences->all(false),
            $year,
            $this->clock->now(),
            $request->getLocale(),
        );

        if ($archive === null) {
            throw new NotFoundHttpException();
        }

        return $this->render('events/archive.html.twig', [
            'archive' => $archive,
        ]);
    }
}
