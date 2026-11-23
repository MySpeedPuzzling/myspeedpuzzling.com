<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPuzzlesNeedingImprovement;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CataloguePagination;
use SpeedPuzzling\Web\Value\ImprovementFilter;
use SpeedPuzzling\Web\Value\ImprovementNeed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A work list for players who like to help with the puzzle database: puzzles missing an EAN or a good picture, each
 * with "Suggest a change" opening the usual proposal form in the modal (docs/features/puzzles-needing-improvement.md).
 * Linked from the footer only, never indexed.
 */
final class PuzzlesNeedingImprovementController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzlesNeedingImprovement $getPuzzlesNeedingImprovement,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle-k-vylepseni',
            'en' => '/en/puzzles-needing-improvement',
            'es' => '/es/puzzles-por-mejorar',
            'ja' => '/ja/puzzles-needing-improvement',
            'fr' => '/fr/puzzles-a-ameliorer',
            'de' => '/de/puzzles-zum-verbessern',
        ],
        name: 'puzzles_needing_improvement',
    )]
    public function __invoke(Request $request): Response
    {
        $brand = $request->query->getString('brand');
        $brandId = Uuid::isValid($brand) ? strtolower($brand) : null;

        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $viewerModerates = $this->isGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS);
        // Guests have no collection - the filter is not offered to them and ignored when typed into the URL
        $inCollection = $profile !== null && $request->query->getBoolean('collection');

        $filter = new ImprovementFilter(
            need: ImprovementNeed::fromQuery($request->query->getString('missing'), $viewerModerates),
            manufacturerId: $brandId,
            collectionPlayerId: $inCollection ? $profile->playerId : null,
            viewerModerates: $viewerModerates,
        );

        $total = $this->getPuzzlesNeedingImprovement->count($filter);
        $pagination = new CataloguePagination($request->query->getInt('page', 1), $total);

        // A page past the end (the list shrinks as puzzles get improved) shows the last one
        if ($pagination->exists() === false) {
            $pagination = new CataloguePagination($pagination->page < 1 ? 1 : $pagination->totalPages, $total);
        }

        // What the pagination keeps: every active filter
        $routeParams = array_filter([
            'missing' => $filter->need->value,
            'brand' => $brandId,
            'collection' => $inCollection ? '1' : null,
        ], static fn (null|string $value): bool => $value !== null && $value !== '');

        return $this->render('puzzles_needing_improvement.html.twig', [
            'puzzles' => $this->getPuzzlesNeedingImprovement->page($filter, $pagination->perPage, $pagination->offset()),
            'brands' => $this->getPuzzlesNeedingImprovement->brands($filter),
            'brand_id' => $brandId,
            'need' => $filter->need,
            'needs' => $viewerModerates ? ImprovementNeed::cases() : [ImprovementNeed::All, ImprovementNeed::Ean, ImprovementNeed::Image],
            'in_collection' => $inCollection,
            'viewer_moderates' => $viewerModerates,
            'total' => $total,
            'pagination' => $pagination,
            'route_params' => $routeParams,
        ]);
    }
}
