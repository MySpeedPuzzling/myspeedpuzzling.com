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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Finish the upload" - the button of an import that stayed `uploading` (Imports home, the import's page): the file
 * may be in object storage although the browser's confirmation never arrived. The same check as the script's
 * (ConfirmImportUploaded), as a plain form: POST + CSRF, then the import's page with what came of it.
 */
final class FinishImportUploadController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private ImportUploadRefusalMessage $refusalMessage,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/finish',
        name: 'data_import_upload_finish',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        try {
            // Another player's import is "not found" - the handler's 404 goes on as it is
            $this->messageBus->dispatch(new ConfirmImportUploaded($importId, $player->playerId));

            $this->addFlash('success', $this->translator->trans('data_import.flash.finished'));
        } catch (HandlerFailedException $exception) {
            $failure = $exception->getPrevious();

            if ($failure instanceof ImportUploadRefused) {
                $this->addFlash('warning', $this->refusalMessage->of($failure->reason));
            } elseif ($failure instanceof FilesystemException) {
                $this->logger->warning('An uploaded import could not be checked - object storage does not answer', [
                    'import_id' => $importId,
                    'exception' => $exception,
                ]);

                $this->addFlash('warning', $this->refusalMessage->of('storage'));
            } else {
                throw $exception;
            }
        }

        return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
    }
}
