<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPuzzlesNeedingImprovement;
use SpeedPuzzling\Web\Value\CataloguePagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A work list for players who like to help with the puzzle database: puzzles missing something, each with
 * "Suggest a change" opening the usual proposal form in the modal. For now puzzles without an EAN. Linked from the
 * footer only, never indexed.
 */
final class PuzzlesNeedingImprovementController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzlesNeedingImprovement $getPuzzlesNeedingImprovement,
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

        $total = $this->getPuzzlesNeedingImprovement->countMissingEan($brandId);
        $pagination = new CataloguePagination($request->query->getInt('page', 1), $total);

        // A page past the end (the list shrinks as puzzles get their EAN) shows the last one
        if ($pagination->exists() === false) {
            $pagination = new CataloguePagination($pagination->page < 1 ? 1 : $pagination->totalPages, $total);
        }

        return $this->render('puzzles_needing_improvement.html.twig', [
            'puzzles' => $this->getPuzzlesNeedingImprovement->missingEan($brandId, $pagination->perPage, $pagination->offset()),
            'brands' => $this->getPuzzlesNeedingImprovement->brandsMissingEan($brandId),
            'brand_id' => $brandId,
            'total' => $total,
            'pagination' => $pagination,
        ]);
    }
}
