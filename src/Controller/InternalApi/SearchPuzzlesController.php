<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Finds puzzles with the site's own search (SearchPuzzle - names in every language, EANs with or without leading
 * zeros, brand codes), approved or not. `ean` and `q` are the same search field - send one of them; `brand` is a
 * brand id or a brand's exact name (any letter case). Secret competition puzzles (hide_until) are not found, like on
 * the site.
 */
final class SearchPuzzlesController extends AbstractController
{
    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly SearchPuzzle $searchPuzzle,
        private readonly ManufacturerResolver $manufacturerResolver,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = min(max($request->query->getInt('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $offset = max($request->query->getInt('offset'), 0);
        $query = trim($request->query->getString('q'));
        $ean = trim($request->query->getString('ean'));
        $brand = trim($request->query->getString('brand'));

        if ($query !== '' && $ean !== '') {
            throw new BadRequestHttpException('Send "q" or "ean", not both - they are the same search.');
        }

        $search = $ean !== '' ? $ean : ($query !== '' ? $query : null);
        $brandId = null;

        if ($brand !== '') {
            $brandId = Uuid::isValid($brand) ? strtolower($brand) : $this->manufacturerResolver->findExisting($brand)?->id->toString();

            if ($brandId === null) {
                return $this->answer(0, $limit, $offset, []);
            }
        }

        $total = $this->searchPuzzle->countByUserInput($brandId, $search, PiecesRange::any(), null);
        $puzzles = $this->searchPuzzle->byUserInput(
            brandId: $brandId,
            search: $search,
            pieces: PiecesRange::any(),
            tag: null,
            sortBy: $search !== null ? 'best-match' : 'a-z',
            offset: $offset,
            limit: $limit,
        );

        return $this->answer($total, $limit, $offset, $puzzles);
    }

    /**
     * @param list<PuzzleOverview> $puzzles
     */
    private function answer(int $total, int $limit, int $offset, array $puzzles): JsonResponse
    {
        return new JsonResponse([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'puzzles' => array_map(static fn (PuzzleOverview $puzzle): array => [
                'puzzleId' => $puzzle->puzzleId,
                'name' => $puzzle->puzzleName,
                'alternativeNames' => $puzzle->puzzleAlternativeNames->toArray(),
                'piecesCount' => $puzzle->piecesCount,
                'manufacturerId' => $puzzle->manufacturerId,
                'manufacturerName' => $puzzle->manufacturerName,
                'ean' => $puzzle->puzzleEan,
                'identificationNumber' => $puzzle->puzzleIdentificationNumber,
                'approved' => $puzzle->puzzleApproved,
                'solvedTimes' => $puzzle->solvedTimes,
            ], $puzzles),
        ]);
    }
}
