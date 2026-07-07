<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class UploadPageSectionImageController extends AbstractController
{
    private const int MAX_FILE_SIZE = 5 * 1024 * 1024;
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ImageOptimizer $imageOptimizer,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/page-section-image-upload',
        name: 'upload_page_section_image',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $competitionId = $request->request->getString('competitionId');
        $seriesId = $request->request->getString('seriesId');

        if (($competitionId === '') === ($seriesId === '')) {
            return new JsonResponse(['error' => 'Provide exactly one of competitionId or seriesId'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($competitionId !== '') {
            $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
        } else {
            $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'No valid file uploaded'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return new JsonResponse(['error' => 'File too large (max 5 MB)'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return new JsonResponse(['error' => 'Unsupported image type'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $ownerId = $competitionId !== '' ? $competitionId : $seriesId;
        $extension = $file->guessExtension() ?? 'jpg';
        $timestamp = $this->clock->now()->getTimestamp();
        $path = "competition-pages/{$ownerId}/" . Uuid::uuid7()->toString() . "-{$timestamp}.{$extension}";

        $this->imageOptimizer->optimize($file->getPathname());

        $stream = fopen($file->getPathname(), 'rb');
        $this->filesystem->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return new JsonResponse(['path' => $path]);
    }
}
