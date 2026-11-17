<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitorPage;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\CompetitorPageLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public competitor list of an event with competitor pages on (docs/features/competitions-management/
 * competitor-pages.md "Competitor list") - everybody going and every pair/team line-up, each a link to their competitor
 * page, with a search in the browser. Linked from the event page and every competitor page; a scanned tag of nobody in
 * the event lands here with `?notice=tag_unknown` (CompetitionTagLanding). Never indexed and never cached: it depends
 * on the viewer (their blocklist, "You").
 */
final class CompetitorListController extends AbstractController
{
    public const string ROUTE = 'competitor_list';
    public const string NOTICE_TAG_UNKNOWN = 'tag_unknown';

    public function __construct(
        private readonly CompetitorPageLoader $competitorPageLoader,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/soutezici/{competitionId}',
            'en' => '/en/competitor/{competitionId}',
            'es' => '/es/competidor/{competitionId}',
            'ja' => '/ja/competitor/{competitionId}',
            'fr' => '/fr/concurrent/{competitionId}',
            'de' => '/de/teilnehmer/{competitionId}',
        ],
        name: self::ROUTE,
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $view = $this->competitorPageLoader->list($competitionId);

        $response = $this->render('competitor_page/list.html.twig', [
            'view' => $view,
            // Only this one value is read - anything else in the query string is ignored
            'tag_unknown' => ($request->query->all()['notice'] ?? null) === self::NOTICE_TAG_UNKNOWN,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
