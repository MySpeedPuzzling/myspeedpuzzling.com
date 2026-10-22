<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PartnerFinder;

use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The partner finder page of a series edition (docs/features/partner-finder.md).
 */
final class EditionPartnersController extends AbstractPartnerFinderPageController
{
    public function __construct(
        readonly private CompetitionRepository $competitionRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/serie/{seriesSlug}/{editionSlug}/partaci',
            'en' => '/en/series/{seriesSlug}/{editionSlug}/partners',
            'es' => '/es/series/{seriesSlug}/{editionSlug}/companeros',
            'ja' => '/ja/series/{seriesSlug}/{editionSlug}/partners',
            'fr' => '/fr/series/{seriesSlug}/{editionSlug}/partenaires',
            'de' => '/de/series/{seriesSlug}/{editionSlug}/partner',
        ],
        name: 'edition_partners',
        methods: ['GET'],
    )]
    public function __invoke(string $seriesSlug, string $editionSlug, Request $request): Response
    {
        $competition = $this->competitionRepository->getBySeriesAndEditionSlug($seriesSlug, $editionSlug);

        // A draft edition, or an edition of a draft series, has no finder - for its team neither
        if ($competition->isHiddenAsDraft()) {
            throw new DraftNotVisible();
        }

        return $this->renderFinder($competition, $request);
    }
}
