<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetPuzzleImagesForAnalysis;
use SpeedPuzzling\Web\Results\PuzzleImageForAnalysis;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The image analysis pipeline's work list (docs/features/internal-api.md, "Puzzle image analysis"): puzzles with a
 * shown image, in id order, a page at a time - `?analyzer=&version=&status=missing|stale|all&limit=1..1000&after=`.
 * Each with the full-size original's URL to download, the picture's `imageFingerprint` to send back with the result,
 * and what is stored for it per analyzer. `nextCursor` is the `after` of the next page, null on the last one.
 */
final class ListPuzzleImagesController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['analyzer', 'version', 'status', 'limit', 'after'];

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
        $analyzer = $analyzerValue !== null ? ImageAnalyzer::tryFrom($analyzerValue) : null;

        if ($analyzerValue !== null && $analyzer === null) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::values()));
        }

        $statusValue = self::parameter($request, 'status') ?? PuzzleImageAnalysisStatus::All->value;
        $status = PuzzleImageAnalysisStatus::tryFrom($statusValue);

        if ($status === null) {
            $errors['status'] = 'must be one of: missing, stale, all.';
        } elseif ($status !== PuzzleImageAnalysisStatus::All && $analyzerValue === null) {
            $errors['analyzer'] = sprintf('is required for status "%s".', $status->value);
        }

        $version = self::parameter($request, 'version');

        if ($version !== null && mb_strlen($version) > PuzzleImageAnalysisInput::MAX_VERSION_LENGTH) {
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

        if ($errors !== [] || $status === null) {
            throw new InternalApiInvalidInput($errors);
        }

        $page = $this->getPuzzleImagesForAnalysis->page($status, $analyzer, $version, $limit, $after);

        return new JsonResponse([
            'puzzles' => array_map($this->serialize(...), $page['items']),
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

    /**
     * @return array<string, mixed>
     */
    private function serialize(PuzzleImageForAnalysis $puzzle): array
    {
        $analyses = [];

        foreach ($puzzle->analyses as $analyzer => $analysis) {
            $analyses[$analyzer] = [
                'analyzerVersion' => $analysis['analyzerVersion'],
                'imageFingerprint' => $analysis['imageFingerprint'],
                'analyzedAt' => $analysis['analyzedAt']?->format(DateTimeInterface::ATOM),
                'receivedAt' => $analysis['receivedAt']->format(DateTimeInterface::ATOM),
            ];
        }

        return [
            'puzzleId' => $puzzle->puzzleId,
            'name' => $puzzle->name,
            'piecesCount' => $puzzle->piecesCount,
            'manufacturer' => $puzzle->manufacturerId !== null
                ? ['id' => $puzzle->manufacturerId, 'name' => $puzzle->manufacturerName]
                : null,
            // The full-size original, as stored
            'imageUrl' => $this->uploaderHelper->getPublicPath($puzzle->image),
            'imageFingerprint' => $puzzle->imageFingerprint,
            'imageRatio' => $puzzle->imageRatio,
            // An object even when empty
            'analyses' => (object) $analyses,
        ];
    }
}
