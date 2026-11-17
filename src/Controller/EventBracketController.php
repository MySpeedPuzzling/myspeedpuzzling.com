<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\Bracket\BracketPageBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public bracket of a one-time event (competition toolkit A9, A-round-formats.md §3.5) - its declared advancement
 * steps as stages of rounds; the qualified of a round only once its results are published.
 */
final class EventBracketController extends AbstractController
{
    public function __construct(
        readonly private BracketPageBuilder $bracketPageBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{slug}/pavouk',
            'en' => '/en/events/{slug}/bracket',
            'es' => '/es/eventos/{slug}/cuadro',
            'ja' => '/ja/イベント/{slug}/bracket',
            'fr' => '/fr/evenements/{slug}/tableau',
            'de' => '/de/veranstaltungen/{slug}/turnierbaum',
        ],
        name: 'event_bracket',
        methods: ['GET'],
    )]
    public function __invoke(
        // An edition's slug is unique only within its series - a standalone event holding the slug wins
        #[MapEntity(expr: 'repository.findOneBy({"slug": slug}, {"series": "DESC"})')] Competition $competition,
    ): Response {
        // A draft exists only for its team and admins - not even the redirect of an edition's bracket, which would tell
        // the draft's series URL. The team gets the page builder's 404: the bracket of an event that is not public is
        // never shown (like the round results and standings pages)
        if ($competition->isHiddenAsDraft() && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString()) === false) {
            throw new DraftNotVisible();
        }

        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_bracket', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $routeParams = ['slug' => (string) $competition->slug];
        $page = $this->bracketPageBuilder->build($competition->id->toString(), 'event_round_results', $routeParams);

        return $this->render('bracket.html.twig', [
            'page' => $page,
            'event_url' => $this->generateUrl('event_detail', $routeParams),
        ]);
    }
}
