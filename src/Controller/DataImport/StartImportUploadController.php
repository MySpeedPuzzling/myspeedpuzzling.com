<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Services\DataImport\ImportUpload;
use SpeedPuzzling\Web\Services\DataImport\ImportUploadRefusalMessage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Starts an import's upload (assets/controllers/data_import_upload_controller.js, docs/features/data-import/upload.md):
 * creates the import in the state `uploading` and answers where the browser PUTs the file straight to object storage -
 * a presigned URL for a server-chosen key and content type and exactly the declared length.
 *
 * JSON only + the `X-CSRF-Token` header. A refusal is `{"error": <reason>, "message": <text for the player>}`.
 * `importId` in the body signs a still uploading import of the player again (its URL ran out) - no new import, no
 * rate-limit hit.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class StartImportUploadController extends AbstractController
{
    private const int MAX_BODY_BYTES = 4096;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ImportUpload $importUpload,
        readonly private ImportUploadRefusalMessage $refusalMessage,
        readonly private RateLimiterFactoryInterface $dataImportUploadLimiter,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/upload',
        name: 'data_import_upload_start',
        requirements: ['_locale' => ImportsHomeController::LOCALES],
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->getContentTypeFormat() !== 'json') {
            return $this->refuse('json_required', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token', '')) === false) {
            return $this->refuse('invalid_csrf_token', Response::HTTP_FORBIDDEN);
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->refuse('sign_in_required', Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode(substr($request->getContent(), 0, self::MAX_BODY_BYTES), associative: true);

        if (!is_array($body) || !is_int($body['bytes'] ?? null) || !is_string($body['name'] ?? null)) {
            return $this->refuse('invalid_request', Response::HTTP_BAD_REQUEST);
        }

        $importId = is_string($body['importId'] ?? null) && Uuid::isValid($body['importId']) ? $body['importId'] : null;

        if (
            $this->importUpload->isRetry($player->playerId, $importId, $body['bytes']) === false
            && $this->dataImportUploadLimiter->create($player->playerId)->consume()->isAccepted() === false
        ) {
            return $this->refuse('rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $started = $this->importUpload->start($player->playerId, $body['name'], $body['bytes'], $importId);
        } catch (ImportUploadRefused $refused) {
            return $this->refuse($refused->reason, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'importId' => $started['importId'],
            'url' => $started['upload']->url,
            'headers' => $started['upload']->headers,
            'expiresAtMs' => $started['upload']->expiresAtMs,
            'confirmUrl' => $this->generateUrl('data_import_upload_confirm', ['importId' => $started['importId']]),
            'importUrl' => $this->generateUrl('data_import_detail', ['importId' => $started['importId']]),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function refuse(string $error, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $error, 'message' => $this->refusalMessage->of($error)],
            $status,
            ['Cache-Control' => 'private, no-store'],
        );
    }
}
