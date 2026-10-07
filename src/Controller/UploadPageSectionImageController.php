<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A gallery photo or sponsor logo of the section form (section_image_upload_controller.js), stored under the page's own
 * prefix (PageSectionOwner::uploadDirectory()) - the section form keeps only such paths. Answers JSON: the stored path,
 * or a translated reason the form shows next to the picture.
 *
 * An upload whose form is never saved stays in storage (docs/TODO.md: prune unreferenced page pictures).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class UploadPageSectionImageController extends AbstractController
{
    public const string MAX_FILE_SIZE = '5M';
    public const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ImageOptimizer $imageOptimizer,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $pageSectionImageUploadLimiter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/nahrat-obrazek-sekce-stranky',
            'en' => '/en/upload-page-section-image',
            'es' => '/es/upload-page-section-image',
            'ja' => '/ja/upload-page-section-image',
            'fr' => '/fr/upload-page-section-image',
            'de' => '/de/upload-page-section-image',
        ],
        name: 'upload_page_section_image',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $owner = PageSectionOwner::fromIds($request->request->getString('competitionId'), $request->request->getString('seriesId'));
        } catch (InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        if ($this->isCsrfTokenValid($owner->csrfTokenId(), $request->request->getString('_token')) === false) {
            return $this->refuse($this->translator->trans('page_sections.error.expired'));
        }

        // Uploads live on the CDN - a page's sections are no free image hosting (config/packages/rate_limiter.php)
        $user = $this->getUser();
        assert($user !== null);

        if ($this->pageSectionImageUploadLimiter->create($user->getUserIdentifier())->consume()->isAccepted() === false) {
            return new JsonResponse(['error' => $this->translator->trans('page_sections.upload.rate_limited')], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->refuse($this->translator->trans('page_sections.upload.no_file'));
        }

        // The validator's own messages, translated to the page's language (an upload over the server's limit included)
        $violations = $this->validator->validate($file, new Image(maxSize: self::MAX_FILE_SIZE, mimeTypes: self::ALLOWED_MIME_TYPES));

        if (count($violations) > 0) {
            return $this->refuse((string) $violations->get(0)->getMessage());
        }

        $extension = $file->guessExtension() ?? 'jpg';
        $path = $owner->uploadDirectory() . Uuid::uuid7()->toString() . '.' . $extension;

        // Also strips EXIF/GPS - a phone photo must not publish where it was taken
        $this->imageOptimizer->optimize($file->getPathname());

        $stream = fopen($file->getPathname(), 'rb');

        if ($stream === false) {
            return $this->refuse($this->translator->trans('page_sections.upload.failed'));
        }

        try {
            $this->filesystem->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        return new JsonResponse(['path' => $path]);
    }

    private function refuse(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
