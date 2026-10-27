<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetFinishedPhotosForAnalysis;
use SpeedPuzzling\Web\Results\FinishedPhotoForAnalysis;
use SpeedPuzzling\Web\Results\FinishedPhotosOfPuzzle;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The image pipeline's work list of players' finished-puzzle photos (docs/features/internal-api.md, "Finished-puzzle
 * photos"; GetFinishedPhotosForAnalysis): `?analyzer=finished_photo&version=&status=missing|stale|all&limit=1..1000`
 * `&perPuzzle=1..20&after=`. Per puzzle its catalogue picture (URL + fingerprint - the stage finds the photo's puzzle by
 * matching it) and its photos, each by `photoFingerprint` with the internal API URL that hands it over and what is
 * stored for it. Never a player, a solving time or a photo's stored path. A page may hold fewer puzzles than `limit`
 * (puzzles with nothing to do are skipped but scanned) - follow `nextCursor` until it is null.
 */
final class ListFinishedPhotosController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['analyzer', 'version', 'status', 'limit', 'perPuzzle', 'after'];

    public function __construct(
        private readonly GetFinishedPhotosForAnalysis $getFinishedPhotosForAnalysis,
        private readonly UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(
        path: '/internal-api/finished-photos',
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

        $analyzerValue = self::parameter($query, 'analyzer') ?? ImageAnalyzer::FinishedPhoto->value;
        $analyzer = ImageAnalyzer::tryFrom($analyzerValue);

        if ($analyzer === null || $analyzer->describesCataloguePicture()) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::finishedPhotoValues()));
        }

        $status = PuzzleImageAnalysisStatus::tryFrom(self::parameter($query, 'status') ?? PuzzleImageAnalysisStatus::All->value);

        if ($status === null) {
            $errors['status'] = 'must be one of: missing, stale, all.';
        }

        $version = self::parameter($query, 'version');

        if ($version !== null && (str_contains($version, "\0") || mb_strlen($version) > PuzzleImageAnalysisInput::MAX_VERSION_LENGTH)) {
            $errors['version'] = sprintf('must be at most %d characters, without NUL.', PuzzleImageAnalysisInput::MAX_VERSION_LENGTH);
        }

        $limit = self::whole($query, 'limit', GetFinishedPhotosForAnalysis::DEFAULT_LIMIT, GetFinishedPhotosForAnalysis::MAX_LIMIT, $errors);
        $perPuzzle = self::whole($query, 'perPuzzle', GetFinishedPhotosForAnalysis::DEFAULT_PER_PUZZLE, GetFinishedPhotosForAnalysis::MAX_PER_PUZZLE, $errors);
        $after = self::parameter($query, 'after');

        if ($after !== null && Uuid::isValid($after) === false) {
            $errors['after'] = 'must be a puzzle id (the nextCursor of the previous page).';
        }

        if ($errors !== [] || $analyzer === null || $status === null) {
            throw new InternalApiInvalidInput($errors);
        }

        $page = $this->getFinishedPhotosForAnalysis->page($status, $analyzer, $version, $limit, $perPuzzle, $after);

        return new JsonResponse([
            'analyzer' => $analyzer->value,
            'puzzles' => array_map($this->puzzle(...), $page['items']),
            'nextCursor' => $page['nextCursor'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function puzzle(FinishedPhotosOfPuzzle $puzzle): array
    {
        return [
            'puzzleId' => $puzzle->puzzleId,
            'piecesCount' => $puzzle->piecesCount,
            // The catalogue picture (full-size original) and its fingerprint - the one the photo is matched against
            'imageUrl' => $this->uploaderHelper->getPublicPath($puzzle->image),
            'imageFingerprint' => $puzzle->imageFingerprint,
            'photos' => array_map(fn (FinishedPhotoForAnalysis $photo): array => [
                'photoFingerprint' => $photo->photoFingerprint,
                // Through the internal API (same token): the stored path names the player and never leaves the app
                'photoUrl' => $this->generateUrl('internal_api_finished_photo', [
                    'puzzleId' => $puzzle->puzzleId,
                    'photoFingerprint' => $photo->photoFingerprint,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'analysis' => $photo->analysis !== null ? [
                    'analyzerVersion' => $photo->analysis['analyzerVersion'],
                    'photoFingerprint' => $photo->analysis['photoFingerprint'],
                    'imageFingerprint' => $photo->analysis['imageFingerprint'],
                    'analyzedAt' => $photo->analysis['analyzedAt']?->format(DateTimeInterface::ATOM),
                    'receivedAt' => $photo->analysis['receivedAt']->format(DateTimeInterface::ATOM),
                ] : null,
            ], $puzzle->photos),
        ];
    }

    /**
     * @param array<mixed> $query
     */
    private static function parameter(array $query, string $name): null|string
    {
        $value = $query[$name] ?? null;

        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $query
     * @param array<string, string> $errors
     */
    private static function whole(array $query, string $name, int $default, int $max, array &$errors): int
    {
        $value = self::parameter($query, $name);

        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);

        if ($parsed === false) {
            $errors[$name] = sprintf('must be a whole number from 1 to %d.', $max);

            return $default;
        }

        return $parsed;
    }
}
