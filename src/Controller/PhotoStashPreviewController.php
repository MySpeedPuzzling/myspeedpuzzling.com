<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The preview of a photo kept from a refused submit - only ever for the player it was kept for.
 */
final class PhotoStashPreviewController extends AbstractController
{
    public function __construct(
        readonly private PhotoStash $photoStash,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/{_locale}/photo-stash/{token}',
        name: 'photo_stash_preview',
        requirements: ['token' => '[0-9a-f]{32}'],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $token): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        $stream = $player !== null ? $this->photoStash->previewStream($token, $player->playerId) : null;

        if ($stream === null) {
            throw $this->createNotFoundException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
