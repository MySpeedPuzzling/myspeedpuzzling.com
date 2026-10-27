<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetPuzzleImagesForAnalysis;
use SpeedPuzzling\Web\Results\PuzzleImageForAnalysis;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisStatus;
use SpeedPuzzling\Web\Value\RequiredImageAnalyses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The image analysis pipeline's work list (docs/features/internal-api.md, "Puzzle image analysis"): puzzles with a
 * shown image, in id order, a page at a time - `?analyzer=&version=&status=missing|stale|all&limit=1..1000&after=`
 * `&requires=artwork,ocr` (only puzzles whose current picture already has a result of each - RequiredImageAnalyses).
 * Each with the full-size original's URL to download, the picture's `imageFingerprint` to send back with the result,
 * what is stored for it and the claims held on it, per analyzer (read-only: the list ignores claims - workers take
 * images through POST /internal-api/puzzle-image-claims). `nextCursor` is the `after` of the next page, null on the
 * last one.
 */
final class ListPuzzleImagesController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['analyzer', 'version', 'status', 'limit', 'after', 'requires'];

    public function __construct(
        private readonly GetPuzzleImagesForAnalysis $getPuzzleImagesForAnalysis,
        private readonly UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-images',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $errors = [];

        foreach ($request->query->keys() as $parameter) {
            if (in_array($parameter, self::KNOWN_PARAMETERS, true) === false) {
                $errors[(string) $parameter] = sprintf('is not a parameter of this endpoint (known: %s).', implode(', ', self::KNOWN_PARAMETERS));
            }
        }

        $analyzerValue = self::parameter($request, 'analyzer');
        $analyzer = $analyzerValue !== null ? ImageAnalyzer::catalogue($analyzerValue) : null;

        if ($analyzerValue !== null && $analyzer === null) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::catalogueValues()));
        }

        $statusValue = self::parameter($request, 'status') ?? PuzzleImageAnalysisStatus::All->value;
        $status = PuzzleImageAnalysisStatus::tryFrom($statusValue);

        if ($status === null) {
            $errors['status'] = 'must be one of: missing, stale, all.';
        } elseif ($status !== PuzzleImageAnalysisStatus::All && $analyzerValue === null) {
            $errors['analyzer'] = sprintf('is required for status "%s".', $status->value);
        }

        $version = self::parameter($request, 'version');

        if ($version !== null && str_contains($version, "\0")) {
            $errors['version'] = 'must not contain NUL characters.';
        } elseif ($version !== null && mb_strlen($version) > PuzzleImageAnalysisInput::MAX_VERSION_LENGTH) {
            $errors['version'] = sprintf('can be at most %d characters long.', PuzzleImageAnalysisInput::MAX_VERSION_LENGTH);
        }

        $limitValue = self::parameter($request, 'limit');
        $limit = GetPuzzleImagesForAnalysis::DEFAULT_LIMIT;

        if ($limitValue !== null) {
            $limit = filter_var($limitValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => GetPuzzleImagesForAnalysis::MAX_LIMIT]]);

            if ($limit === false) {
                $errors['limit'] = sprintf('must be a whole number from 1 to %d.', GetPuzzleImagesForAnalysis::MAX_LIMIT);
                $limit = GetPuzzleImagesForAnalysis::DEFAULT_LIMIT;
            }
        }

        $after = self::parameter($request, 'after');

        if ($after !== null && Uuid::isValid($after) === false) {
            $errors['after'] = 'must be a puzzle id (the nextCursor of the previous page).';
        }

        $requires = RequiredImageAnalyses::none();
        $requiresValue = self::parameter($request, 'requires');

        if (is_array($request->query->all()['requires'] ?? null)) {
            // requires[]=… would otherwise be dropped unnoticed - and the filter with it
            $errors['requires'] = 'must be one comma-separated value, e.g. requires=artwork,ocr.';
        } elseif ($requiresValue !== null) {
            $values = array_values(array_filter(array_map(trim(...), explode(',', $requiresValue)), static fn (string $value): bool => $value !== ''));
            $parsed = RequiredImageAnalyses::fromValues($values, $analyzer);

            if (is_string($parsed)) {
                $errors['requires'] = $parsed;
            } else {
                $requires = $parsed;
            }
        }

        if ($errors !== [] || $status === null) {
            throw new InternalApiInvalidInput($errors);
        }

        $page = $this->getPuzzleImagesForAnalysis->page($status, $analyzer, $version, $limit, $after, $requires);

        return new JsonResponse([
            'puzzles' => array_map(
                fn (PuzzleImageForAnalysis $puzzle): array => PuzzleImageForAnalysisJson::of($puzzle, $this->uploaderHelper->getPublicPath($puzzle->image)),
                $page['items'],
            ),
            'nextCursor' => $page['nextCursor'],
        ]);
    }

    private static function parameter(Request $request, string $name): null|string
    {
        $value = $request->query->all()[$name] ?? null;

        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
