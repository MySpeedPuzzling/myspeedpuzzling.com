<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetPuzzleDifficultyLabels;
use SpeedPuzzling\Web\Results\PuzzleDifficultyLabel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Training labels for the image pipeline's difficulty model (docs/features/internal-api.md, "Puzzle difficulty labels
 * and estimates"): `?limit=1..5000 (1000)&after=<puzzleId>`, in puzzle id order - every puzzle with at least one as-of
 * difficulty index, secret puzzles left out, aggregates only. `nextCursor` is the `after` of the next page, null on
 * the last one.
 */
final class ListPuzzleDifficultyLabelsController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['limit', 'after'];

    public function __construct(
        private readonly GetPuzzleDifficultyLabels $getPuzzleDifficultyLabels,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-difficulty-labels',
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

        $limit = GetPuzzleDifficultyLabels::DEFAULT_PAGE_LIMIT;

        if (isset($query['limit'])) {
            $limit = filter_var($query['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => GetPuzzleDifficultyLabels::MAX_PAGE_LIMIT]]);

            if ($limit === false) {
                $errors['limit'] = sprintf('must be a whole number from 1 to %d.', GetPuzzleDifficultyLabels::MAX_PAGE_LIMIT);
                $limit = GetPuzzleDifficultyLabels::DEFAULT_PAGE_LIMIT;
            }
        }

        $after = is_string($query['after'] ?? null) && $query['after'] !== '' ? $query['after'] : null;

        if (isset($query['after']) && ($after === null || Uuid::isValid($after) === false)) {
            $errors['after'] = 'must be a puzzle id (the nextCursor of the previous page).';
        }

        if ($errors !== []) {
            throw new InternalApiInvalidInput($errors);
        }

        $page = $this->getPuzzleDifficultyLabels->page($limit, $after);

        return new JsonResponse([
            'labels' => array_map(
                static fn (PuzzleDifficultyLabel $label): array => [
                    'puzzleId' => $label->puzzleId,
                    'logDifficulty' => $label->logDifficulty,
                    'labelSource' => $label->labelSource,
                    'meanLogIndex' => $label->meanLogIndex,
                    'indexCount' => $label->indexCount,
                    'piecesCount' => $label->piecesCount,
                    'manufacturerId' => $label->manufacturerId,
                    'addedAt' => $label->addedAt?->format(DateTimeInterface::ATOM),
                    'imageFingerprint' => $label->imageFingerprint,
                ],
                $page['items'],
            ),
            'nextCursor' => $page['nextCursor'],
        ]);
    }
}
