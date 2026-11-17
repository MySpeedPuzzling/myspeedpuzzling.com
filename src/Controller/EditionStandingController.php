<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\Standings\StandingPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public page of a published standing of a series edition (competition toolkit A7, A-round-formats.md §3.6).
 */
final class EditionStandingController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRepository $competitionRepository,
        readonly private StandingPageBuilder $standingPageBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/serie/{seriesSlug}/{editionSlug}/poradi/{standingSlug}',
            'en' => '/en/series/{seriesSlug}/{editionSlug}/standings/{standingSlug}',
            'es' => '/es/series/{seriesSlug}/{editionSlug}/clasificacion/{standingSlug}',
            'ja' => '/ja/series/{seriesSlug}/{editionSlug}/standings/{standingSlug}',
            'fr' => '/fr/series/{seriesSlug}/{editionSlug}/classement/{standingSlug}',
            'de' => '/de/series/{seriesSlug}/{editionSlug}/gesamtwertung/{standingSlug}',
        ],
        name: 'edition_standing',
    )]
    public function __invoke(string $seriesSlug, string $editionSlug, string $standingSlug): Response
    {
        $competition = $this->competitionRepository->getBySeriesAndEditionSlug($seriesSlug, $editionSlug);

        // A draft edition, or an edition of a draft series, exists only for its team and admins; the team gets the page
        // builder's 404 - standings of an event that is not public are never shown
        if ($competition->isHiddenAsDraft() && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString()) === false) {
            throw new DraftNotVisible();
        }

        $page = $this->standingPageBuilder->build($competition->id->toString(), $standingSlug, $competition->series?->name);

        return $this->render('standing.html.twig', [
            'page' => $page,
            'event_url' => $this->generateUrl('edition_detail', ['seriesSlug' => $seriesSlug, 'editionSlug' => $editionSlug]),
            'standing_route' => 'edition_standing',
            'round_route' => 'edition_round_results',
            'route_params' => ['seriesSlug' => $seriesSlug, 'editionSlug' => $editionSlug],
        ]);
    }
}
