<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Services\DataImport\ImportUploadRefusalMessage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "The file is up" (docs/features/data-import/upload.md): the browser says its PUT finished; the object is checked
 * and the import moves to `uploaded`. Another player's import is a 404; an object that is not there or has another
 * size a 409 with the reason; object storage not answering a 503 - the browser may ask again, nothing changed.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ConfirmImportUploadController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private ImportUploadRefusalMessage $refusalMessage,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/uploaded',
        name: 'data_import_upload_confirm',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $importId): JsonResponse
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token', '')) === false) {
            return $this->refuse('invalid_csrf_token', Response::HTTP_FORBIDDEN);
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->refuse('sign_in_required', Response::HTTP_UNAUTHORIZED);
        }

        try {
            // Another player's import is "not found" - the handler's 404 goes on as it is
            $this->messageBus->dispatch(new ConfirmImportUploaded($importId, $player->playerId));
        } catch (HandlerFailedException $exception) {
            $failure = $exception->getPrevious();

            if ($failure instanceof ImportUploadRefused) {
                return $this->refuse($failure->reason, Response::HTTP_CONFLICT);
            }

            if ($failure instanceof FilesystemException) {
                $this->logger->warning('An uploaded import could not be checked - object storage does not answer', [
                    'import_id' => $importId,
                    'exception' => $exception,
                ]);

                return $this->refuse('storage', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            throw $exception;
        }

        return new JsonResponse([
            'status' => 'uploaded',
            'importUrl' => $this->generateUrl('data_import_detail', ['importId' => $importId]),
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
