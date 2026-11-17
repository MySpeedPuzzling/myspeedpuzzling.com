<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\Bracket\BracketPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public bracket of a series edition (competition toolkit A9, A-round-formats.md §3.5).
 */
final class EditionBracketController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRepository $competitionRepository,
        readonly private BracketPageBuilder $bracketPageBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/serie/{seriesSlug}/{editionSlug}/pavouk',
            'en' => '/en/series/{seriesSlug}/{editionSlug}/bracket',
            'es' => '/es/series/{seriesSlug}/{editionSlug}/cuadro',
            'ja' => '/ja/series/{seriesSlug}/{editionSlug}/bracket',
            'fr' => '/fr/series/{seriesSlug}/{editionSlug}/tableau',
            'de' => '/de/series/{seriesSlug}/{editionSlug}/turnierbaum',
        ],
        name: 'edition_bracket',
        methods: ['GET'],
    )]
    public function __invoke(string $seriesSlug, string $editionSlug): Response
    {
        $competition = $this->competitionRepository->getBySeriesAndEditionSlug($seriesSlug, $editionSlug);

        // A draft edition, or an edition of a draft series, exists only for its team and admins; the team gets the page
        // builder's 404 - the bracket of an event that is not public is never shown
        if ($competition->isHiddenAsDraft() && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString()) === false) {
            throw new DraftNotVisible();
        }

        $routeParams = ['seriesSlug' => $seriesSlug, 'editionSlug' => $editionSlug];
        $page = $this->bracketPageBuilder->build($competition->id->toString(), 'edition_round_results', $routeParams, $competition->series?->name);

        return $this->render('bracket.html.twig', [
            'page' => $page,
            'event_url' => $this->generateUrl('edition_detail', $routeParams),
        ]);
    }
}
