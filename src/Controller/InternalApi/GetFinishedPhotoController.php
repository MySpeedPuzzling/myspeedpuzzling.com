<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetFinishedPhotosForAnalysis;
use SpeedPuzzling\Web\Services\FinishedPhotoRendition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands one finished-puzzle photo to the image pipeline (docs/features/internal-api.md, "Finished-puzzle photos"): the
 * `photoUrl` of the work list. The image proxy's 1200 px JPEG rendition (FinishedPhotoRendition: metadata stripped),
 * read through the app so the stored path - it names the player - never leaves it. 404 when the fingerprint is not one
 * of the puzzle's current photos of a public player or the puzzle is hidden; 503 (+ Retry-After) when the proxy fails.
 * Not cached anywhere on the way.
 */
final class GetFinishedPhotoController extends AbstractController
{
    public function __construct(
        private readonly GetFinishedPhotosForAnalysis $getFinishedPhotosForAnalysis,
        private readonly FinishedPhotoRendition $finishedPhotoRendition,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles/{puzzleId}/finished-photos/{photoFingerprint}',
        name: 'internal_api_finished_photo',
        requirements: ['puzzleId' => FirstTryConflictsController::ID_REQUIREMENT, 'photoFingerprint' => '[0-9a-f]{16}'],
        methods: ['GET'],
    )]
    public function __invoke(string $puzzleId, string $photoFingerprint): Response
    {
        $path = $this->getFinishedPhotosForAnalysis->photoPath($puzzleId, $photoFingerprint);
        $bytes = $path !== null ? $this->finishedPhotoRendition->bytes($path) : null;

        if ($bytes === null) {
            throw $this->createNotFoundException('No such finished photo of this puzzle.');
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
