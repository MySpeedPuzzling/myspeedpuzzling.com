<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayImages;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A picture for the display editor (D-displays-overlays.md §8.1 `upload_competition_display_image`, §10.1): a multipart
 * POST with the file in `file` and the `X-CSRF-Token` header (`csrf_token('official_results')`, as the editor's Save) →
 * - 200 `{path, url}` - `path` goes into the design (image element `props.path`, rotator `props.images[].path`,
 *   `canvas.background.image.path`), `url` is what the display will load (DisplayImages::url);
 * - 401 `sign_in_required`, 403 `forbidden` (COMPETITION_TOOLKIT_MANAGE on the display's own event) /
 *   `invalid_csrf_token`, 404 `not_found`;
 * - 429 `rate_limited` (60 pictures an hour per player), 413 `too_large` (over 5 MB), 415 `unsupported_type` (only
 *   JPEG, PNG, WebP, GIF - by the file's content; SVG never), 422 `no_file` / `invalid_image` / `upload_failed`.
 * Every refusal carries a translated `message` the editor shows as it is.
 *
 * Stored at `competition-displays/<competitionId>/<uuid7>.<ext>` - the one prefix DisplayDesignSanitizer accepts for
 * the event. A picture never saved into a design stays in storage (docs/TODO.md: prune unreferenced uploads); one
 * dropped from every design is deleted after the save (DeleteCompetitionDisplayImages).
 */
final class UploadCompetitionDisplayImageController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionDisplays $getCompetitionDisplays,
        private readonly OfficialResultsApi $api,
        private readonly Filesystem $filesystem,
        private readonly ImageOptimizer $imageOptimizer,
        private readonly DisplayImages $displayImages,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $competitionDisplayImageUploadLimiter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/editor-obrazovky/{displayId}/obrazek',
            'en' => '/en/display-editor/{displayId}/image',
            'es' => '/es/display-editor/{displayId}/image',
            'ja' => '/ja/display-editor/{displayId}/image',
            'fr' => '/fr/display-editor/{displayId}/image',
            'de' => '/de/display-editor/{displayId}/image',
        ],
        name: 'upload_competition_display_image',
        requirements: ['displayId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $displayId): JsonResponse
    {
        try {
            $display = $this->getCompetitionDisplays->byId($displayId);
        } catch (CompetitionDisplayNotFound) {
            return OfficialResultsApi::error('not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        // The voter on the event of the display loaded from the database, never on an id from the request
        $playerId = $this->api->authorise($request, $display->competitionId, write: false, attribute: CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        if ($this->api->hasValidCsrfHeader($request) === false) {
            return OfficialResultsApi::error('invalid_csrf_token', JsonResponse::HTTP_FORBIDDEN);
        }

        // Uploads live on the CDN - an event's displays are no free image hosting
        if ($this->competitionDisplayImageUploadLimiter->create($playerId)->consume()->isAccepted() === false) {
            return $this->refuse('rate_limited', JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            // A body over PHP's post_max_size arrives without any file
            $tooLarge = $request->server->getInt('CONTENT_LENGTH') > DisplayImages::MAX_FILE_BYTES;

            return $tooLarge
                ? $this->refuse('too_large', JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE)
                : $this->refuse('no_file', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($file->isValid() === false) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? $this->refuse('too_large', JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE)
                : $this->refuse('upload_failed', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($file->getSize() > DisplayImages::MAX_FILE_BYTES) {
            return $this->refuse('too_large', JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        // By the content, never by the name or the browser's word for it
        $mimeType = (string) $file->getMimeType();
        $path = DisplayImages::newPath($display->competitionId, $mimeType);

        if ($path === null) {
            return $this->refuse('unsupported_type', JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if (count($this->validator->validate($file, new Image(mimeTypes: array_keys(DisplayImages::EXTENSIONS)))) > 0) {
            return $this->refuse('invalid_image', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Also strips EXIF/GPS and scales anything over 2000 px down (PNG transparency stays)
        $this->imageOptimizer->optimize($file->getPathname());

        $stream = fopen($file->getPathname(), 'rb');

        if ($stream === false) {
            return $this->refuse('upload_failed', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->filesystem->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        return OfficialResultsApi::json([
            'path' => $path,
            'url' => $this->displayImages->url($path),
        ]);
    }

    private function refuse(string $error, int $status): JsonResponse
    {
        return OfficialResultsApi::error($error, $status, [
            'message' => $this->translator->trans('competition_display.image_upload.' . $error),
        ]);
    }
}
