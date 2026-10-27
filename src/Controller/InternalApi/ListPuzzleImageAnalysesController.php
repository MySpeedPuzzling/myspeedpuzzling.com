<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetPuzzleImageAnalyses;
use SpeedPuzzling\Web\Results\PuzzleImageAnalysisResult;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The stored results of one analyzer in bulk, payloads included (docs/features/internal-api.md, "Puzzle image
 * analysis" - bulk read): `?analyzer=&limit=1..1000 (500)&after=<puzzleId>&onlyCurrent=1`, in puzzle id order,
 * hidden images left out. `onlyCurrent=1` gives only analyses of the picture each puzzle has now. `nextCursor` is the
 * `after` of the next page, null on the last one - a page ends after `limit` rows or once its results pass ~8 MB
 * (GetPuzzleImageAnalyses::MAX_PAGE_BYTES).
 */
final class ListPuzzleImageAnalysesController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['analyzer', 'limit', 'after', 'onlyCurrent'];

    public function __construct(
        private readonly GetPuzzleImageAnalyses $getPuzzleImageAnalyses,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-image-analyses',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $errors = [];
        $query = $request->query->all();

        foreach (array_keys($query) as $parameter) {
            if (in_array($parameter, self::KNOWN_PARAMETERS, true) === false) {
                $errors[(string) $parameter] = sprintf('is not a parameter of this endpoint (known: %s).', implode(', ', self::KNOWN_PARAMETERS));
            }
        }

        $analyzerValue = is_string($query['analyzer'] ?? null) ? $query['analyzer'] : null;
        $analyzer = $analyzerValue !== null ? ImageAnalyzer::catalogue($analyzerValue) : null;

        if ($analyzer === null) {
            $errors['analyzer'] = sprintf('is required - one of: %s.', implode(', ', ImageAnalyzer::catalogueValues()));
        }

        $limit = GetPuzzleImageAnalyses::DEFAULT_PAGE_LIMIT;

        if (isset($query['limit'])) {
            $limit = filter_var($query['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => GetPuzzleImageAnalyses::MAX_PAGE_LIMIT]]);

            if ($limit === false) {
                $errors['limit'] = sprintf('must be a whole number from 1 to %d.', GetPuzzleImageAnalyses::MAX_PAGE_LIMIT);
                $limit = GetPuzzleImageAnalyses::DEFAULT_PAGE_LIMIT;
            }
        }

        $after = is_string($query['after'] ?? null) && $query['after'] !== '' ? $query['after'] : null;

        if ($after !== null && Uuid::isValid($after) === false) {
            $errors['after'] = 'must be a puzzle id (the nextCursor of the previous page).';
        }

        $onlyCurrent = $query['onlyCurrent'] ?? '0';

        if (in_array($onlyCurrent, ['0', '1', 'true', 'false'], true) === false) {
            $errors['onlyCurrent'] = 'must be 1 or 0.';
        }

        if ($errors !== [] || $analyzer === null) {
            throw new InternalApiInvalidInput($errors);
        }

        $page = $this->getPuzzleImageAnalyses->page($analyzer, $limit, $after, in_array($onlyCurrent, ['1', 'true'], true));

        return new JsonResponse([
            'analyzer' => $analyzer->value,
            'items' => array_map(
                static fn (PuzzleImageAnalysisResult $analysis): array => PuzzleImageAnalysisJson::of($analysis),
                $page['items'],
            ),
            'nextCursor' => $page['nextCursor'],
        ]);
    }
}
