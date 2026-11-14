<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Services\DataImport\ImportUpload;
use SpeedPuzzling\Web\Services\DataImport\ImportUploadRefusalMessage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Bring the file" (docs/features/data-import/upload.md). The page's script uploads the picked file straight to
 * object storage (StartImportUploadController → ConfirmImportUploadController); this POST is the fallback - the same
 * form sent as it is when that can not work (no script, a store the browser can not reach). Same limits, same
 * messages. A full-page POST: redirect on success, 422 with the reason otherwise.
 */
final class BringImportFileController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ImportUpload $importUpload,
        readonly private ImportUploadRefusalMessage $refusalMessage,
        readonly private RateLimiterFactoryInterface $dataImportUploadLimiter,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/new',
        name: 'data_import_new',
        requirements: ['_locale' => ImportsHomeController::LOCALES],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $refusal = null;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            $received = $this->receive($request, $player->playerId);

            if ($received['importId'] !== null) {
                return $this->redirectToRoute('data_import_detail', ['importId' => $received['importId']]);
            }

            $refusal = $received['refusal'];
        }

        return $this->render('data_import/new.html.twig', [
            'refusal' => $refusal !== null ? $this->refusalMessage->of($refusal) : null,
            'refusal_messages' => $this->refusalMessage->all(),
            'accept' => DataImportFileKind::acceptAttribute(),
            'max_bytes' => DataImportLimits::MAX_FILE_BYTES,
            'max_megabytes' => intdiv(DataImportLimits::MAX_FILE_BYTES, 1024 * 1024),
            'keep_days' => DataImportLimits::KEEP_FILE_DAYS,
        ], new Response(status: $refusal !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array{importId: null|string, refusal: null|string} the new import, or why the file was not taken
     */
    private function receive(Request $request, string $playerId): array
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return self::refused('no_file');
        }

        if ($file->isValid() === false) {
            // PHP's own limits speak first for a file far over ours
            return self::refused(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'too_large' : 'no_file');
        }

        if ($this->dataImportUploadLimiter->create($playerId)->consume()->isAccepted() === false) {
            return self::refused('rate_limited');
        }

        try {
            $importId = $this->importUpload->receive($playerId, $file);
        } catch (ImportUploadRefused $refused) {
            return self::refused($refused->reason);
        } catch (FilesystemException $e) {
            $this->logger->warning('An import\'s file sent with the form could not be stored', [
                'exception' => $e,
            ]);

            return self::refused('storage');
        }

        return ['importId' => $importId, 'refusal' => null];
    }

    /**
     * @return array{importId: null, refusal: string}
     */
    private static function refused(string $reason): array
    {
        return ['importId' => null, 'refusal' => $reason];
    }
}
