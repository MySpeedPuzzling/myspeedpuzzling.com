<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PhotoUploadRefused;
use SpeedPuzzling\Web\Services\PhotoStash\DirectPhotoUpload;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Starts a background photo upload (assets/photo_upload.js, docs/features/photo-uploads/README.md): answers where the
 * browser PUTs the photo straight to object storage, and the token the form sends instead of the file.
 *
 * Every refusal is a JSON `{"error": …}` the browser does not show - it simply sends the photo with the form, as before,
 * and the form's validation speaks to the player. JSON only + the `X-CSRF-Token` header (stateless id `photo_upload`).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PhotoUploadStartController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'photo_upload';
    private const int MAX_BODY_BYTES = 4096;

    public function __construct(
        readonly private DirectPhotoUpload $directPhotoUpload,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CsrfTokenManagerInterface $csrfTokenManager,
        readonly private RateLimiterFactoryInterface $photoUploadLimiter,
        #[Autowire(param: 'directPhotoUploads')]
        readonly private bool $directPhotoUploads,
    ) {
    }

    #[Route(
        path: '/{_locale}/photo-upload',
        name: 'photo_upload_start',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        // The kill switch (DIRECT_PHOTO_UPLOADS): the pages no longer ask, an old page open in a tab gets this
        if ($this->directPhotoUploads === false) {
            return self::refuse('disabled', Response::HTTP_NOT_FOUND);
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return self::refuse('json_required', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if ($this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token', ''))) === false) {
            return self::refuse('invalid_csrf_token', Response::HTTP_FORBIDDEN);
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return self::refuse('sign_in_required', Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode(substr($request->getContent(), 0, self::MAX_BODY_BYTES), associative: true);

        if (!is_array($body) || !is_int($body['bytes'] ?? null) || !is_string($body['type'] ?? null)) {
            return self::refuse('invalid_request', Response::HTTP_BAD_REQUEST);
        }

        $token = self::token($body, 'token');

        // A retry of the same photo signs its place again - only a new place counts against the limit
        if ($token === null && $this->photoUploadLimiter->create($player->playerId)->consume()->isAccepted() === false) {
            return self::refuse('rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $upload = $this->directPhotoUpload->start(
                playerId: $player->playerId,
                fileName: is_string($body['name'] ?? null) ? $body['name'] : '',
                mime: $body['type'],
                bytes: $body['bytes'],
                token: $token,
                replaces: self::token($body, 'replaces'),
            );
        } catch (PhotoUploadRefused $refused) {
            return self::refuse(
                $refused->reason,
                $refused->reason === 'storage' ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse([
            'token' => $upload->token,
            'url' => $upload->url,
            'headers' => $upload->headers,
            'expiresAtMs' => $upload->expiresAtMs,
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    /**
     * @param array<mixed> $body
     */
    private static function token(array $body, string $key): null|string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && PhotoStash::isToken($value) ? $value : null;
    }

    private static function refuse(string $error, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $error], $status, ['Cache-Control' => 'private, no-store']);
    }
}
