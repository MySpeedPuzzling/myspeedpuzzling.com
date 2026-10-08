<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EditionDetailLegacyRedirectController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRepository $competitionRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/edice/{competitionId}',
            'en' => '/en/edition/{competitionId}',
            'es' => '/es/edition/{competitionId}',
            'ja' => '/ja/edition/{competitionId}',
            'fr' => '/fr/edition/{competitionId}',
            'de' => '/de/edition/{competitionId}',
        ],
        name: 'edition_detail_legacy',
    )]
    public function __invoke(string $competitionId): Response
    {
        $competition = $this->competitionRepository->get($competitionId);

        // A draft (or an edition of a draft series) exists only for its team and admins - no redirect telling its URL
        // (docs/features/organizations/README.md "Drafts", P5)
        if ($competition->isHiddenAsDraft() && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString()) === false) {
            throw new DraftNotVisible();
        }

        if ($competition->series === null || $competition->series->slug === null || $competition->slug === null) {
            return $this->redirectToRoute('events');
        }

        return $this->redirectToRoute('edition_detail', [
            'seriesSlug' => $competition->series->slug,
            'editionSlug' => $competition->slug,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
