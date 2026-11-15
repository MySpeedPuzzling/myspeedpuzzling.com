<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Message\CreateImportFromGoogleSheet;
use SpeedPuzzling\Web\Services\DataImport\ImportUpload;
use SpeedPuzzling\Web\Services\DataImport\ImportUploadRefusalMessage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\GoogleSheetLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Bring the file" (docs/features/data-import/upload.md, google-sheets.md): a Google Sheets link first, the file
 * picker below it - two forms of one page, both posted here.
 *
 * The link: a plain form POST. A link that is no Google sheet's is answered under its field (422); a good one makes
 * the import at once (CreateImportFromGoogleSheet - the worker fetches it) and goes to its page.
 *
 * The file: the page's script uploads the picked file straight to
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
        readonly private RateLimiterFactoryInterface $dataImportGoogleLimiter,
        readonly private MessageBusInterface $messageBus,
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
        $link = '';
        $linkRefusal = null;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            // The link's form has this field, the file's form does not
            $viaLink = $request->request->has('google_link');
            $link = $viaLink ? mb_substr($request->request->getString('google_link'), 0, 2000) : '';
            $received = $viaLink ? $this->receiveLink($link, $player->playerId) : $this->receive($request, $player->playerId);

            if ($received['importId'] !== null) {
                return $this->redirectToRoute('data_import_detail', ['importId' => $received['importId']]);
            }

            $refusal = $viaLink ? null : $received['refusal'];
            $linkRefusal = $viaLink ? $received['refusal'] : null;
        }

        return $this->render('data_import/new.html.twig', [
            'refusal' => $refusal !== null ? $this->refusalMessage->of($refusal) : null,
            'google_link' => $link,
            'link_refusal' => $linkRefusal !== null ? $this->refusalMessage->of($linkRefusal) : null,
            'refusal_messages' => $this->refusalMessage->all(),
            'accept' => DataImportFileKind::acceptAttribute(),
            'max_bytes' => DataImportLimits::MAX_FILE_BYTES,
            'max_megabytes' => intdiv(DataImportLimits::MAX_FILE_BYTES, 1024 * 1024),
            'keep_days' => DataImportLimits::KEEP_FILE_DAYS,
        ], new Response(status: $refusal !== null || $linkRefusal !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array{importId: null|string, refusal: null|string} the new import, or why the link was not taken
     */
    private function receiveLink(string $pasted, string $playerId): array
    {
        $link = GoogleSheetLink::parse($pasted);

        if ($link->spreadsheetId === null) {
            // A mistyped link is the player's own news - nothing to log
            return self::refused($link->problem ?? GoogleSheetLink::NOT_A_SHEET);
        }

        if ($this->dataImportGoogleLimiter->create($playerId)->consume()->isAccepted() === false) {
            return self::refused('google_rate_limited');
        }

        $importId = Uuid::uuid7()->toString();

        try {
            $this->messageBus->dispatch(new CreateImportFromGoogleSheet($importId, $playerId, $link->spreadsheetId));
        } catch (HandlerFailedException $exception) {
            $refusal = $exception->getPrevious();

            if ($refusal instanceof ImportUploadRefused) {
                return self::refused($refusal->reason === 'type' ? GoogleSheetLink::NOT_A_SHEET : $refusal->reason);
            }

            throw $exception;
        }

        return ['importId' => $importId, 'refusal' => null];
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

        // The upload the page's script started for this file and could not finish: the same import, counted once
        $startedId = $request->request->getString('import_id');
        $startedId = Uuid::isValid($startedId) && $this->importUpload->continues($playerId, $startedId, $file) ? $startedId : null;

        if ($startedId === null && $this->dataImportUploadLimiter->create($playerId)->consume()->isAccepted() === false) {
            return self::refused('rate_limited');
        }

        try {
            $importId = $this->importUpload->receive($playerId, $file, $startedId);
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
