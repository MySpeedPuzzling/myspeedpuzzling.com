<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\Standings\StandingPageBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public page of a published standing of a one-time event (competition toolkit A7, A-round-formats.md §3.6) - the
 * sum of its rounds whose official results are published, "Provisional" until all are.
 */
final class EventStandingController extends AbstractController
{
    public function __construct(
        readonly private StandingPageBuilder $standingPageBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{slug}/poradi/{standingSlug}',
            'en' => '/en/events/{slug}/standings/{standingSlug}',
            'es' => '/es/eventos/{slug}/clasificacion/{standingSlug}',
            'ja' => '/ja/イベント/{slug}/standings/{standingSlug}',
            'fr' => '/fr/evenements/{slug}/classement/{standingSlug}',
            'de' => '/de/veranstaltungen/{slug}/gesamtwertung/{standingSlug}',
        ],
        name: 'event_standing',
    )]
    public function __invoke(
        // An edition's slug is unique only within its series - a standalone event holding the slug wins
        #[MapEntity(expr: 'repository.findOneBy({"slug": slug}, {"series": "DESC"})')] Competition $competition,
        string $standingSlug,
    ): Response {
        // A draft exists only for its team and admins - not even the redirect of an edition's standing, which would tell
        // the draft's series URL. The team gets the page builder's 404: standings of an event that is not public are
        // never shown (like the round results pages)
        if ($competition->isHiddenAsDraft() && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString()) === false) {
            throw new DraftNotVisible();
        }

        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_standing', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
                'standingSlug' => $standingSlug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $page = $this->standingPageBuilder->build($competition->id->toString(), $standingSlug);

        return $this->render('standing.html.twig', [
            'page' => $page,
            'event_url' => $this->generateUrl('event_detail', ['slug' => $competition->slug]),
            'standing_route' => 'event_standing',
            'round_route' => 'event_round_results',
            'route_params' => ['slug' => $competition->slug],
        ]);
    }
}
