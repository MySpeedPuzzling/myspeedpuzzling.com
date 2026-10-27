<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetFinishedPhotoAnalyses;
use SpeedPuzzling\Web\Results\FinishedPhotoAnalysisResult;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bulk read of the stored finished-photo analyses (docs/features/internal-api.md, "Finished-puzzle photos"):
 * `?analyzer=finished_photo&limit=1..1000&after=&onlyCurrent=1` - payloads included, in analysis order (keyset:
 * `after` = the previous page's `nextCursor`), puzzles hidden now left out. Each item names the puzzle and the photo's
 * fingerprint, never a player or a solving time; `current` says whether it still describes one of the puzzle's photos
 * matched against its current catalogue picture.
 */
final class ListFinishedPhotoAnalysesController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['analyzer', 'limit', 'after', 'onlyCurrent'];

    public function __construct(
        private readonly GetFinishedPhotoAnalyses $getFinishedPhotoAnalyses,
    ) {
    }

    #[Route(
        path: '/internal-api/finished-photo-analyses',
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

        $analyzerValue = is_string($query['analyzer'] ?? null) && $query['analyzer'] !== '' ? $query['analyzer'] : ImageAnalyzer::FinishedPhoto->value;
        $analyzer = ImageAnalyzer::tryFrom($analyzerValue);

        if ($analyzer === null || $analyzer->describesCataloguePicture()) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::finishedPhotoValues()));
        }

        $limit = GetFinishedPhotoAnalyses::DEFAULT_PAGE_LIMIT;

        if (isset($query['limit'])) {
            $limit = filter_var($query['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => GetFinishedPhotoAnalyses::MAX_PAGE_LIMIT]]);

            if ($limit === false) {
                $errors['limit'] = sprintf('must be a whole number from 1 to %d.', GetFinishedPhotoAnalyses::MAX_PAGE_LIMIT);
                $limit = GetFinishedPhotoAnalyses::DEFAULT_PAGE_LIMIT;
            }
        }

        $after = is_string($query['after'] ?? null) && $query['after'] !== '' ? $query['after'] : null;

        if ($after !== null && Uuid::isValid($after) === false) {
            $errors['after'] = 'must be the nextCursor of the previous page.';
        }

        $onlyCurrent = $query['onlyCurrent'] ?? '0';

        if (in_array($onlyCurrent, ['0', '1', 'true', 'false'], true) === false) {
            $errors['onlyCurrent'] = 'must be 1 or 0.';
        }

        if ($errors !== [] || $analyzer === null) {
            throw new InternalApiInvalidInput($errors);
        }

        $page = $this->getFinishedPhotoAnalyses->page($analyzer, $limit, $after, in_array($onlyCurrent, ['1', 'true'], true));

        return new JsonResponse([
            'analyzer' => $analyzer->value,
            'items' => array_map(static fn (FinishedPhotoAnalysisResult $analysis): array => [
                'puzzleId' => $analysis->puzzleId,
                'analyzer' => $analysis->analyzer->value,
                'analyzerVersion' => $analysis->analyzerVersion,
                'photoFingerprint' => $analysis->photoFingerprint,
                'imageFingerprint' => $analysis->imageFingerprint,
                'analyzedAt' => $analysis->analyzedAt?->format(DateTimeInterface::ATOM),
                'receivedAt' => $analysis->receivedAt->format(DateTimeInterface::ATOM),
                'current' => $analysis->current,
                'result' => $analysis->result,
            ], $page['items']),
            'nextCursor' => $page['nextCursor'],
        ]);
    }
}
