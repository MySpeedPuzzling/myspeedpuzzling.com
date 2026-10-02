<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Services\GetResultImage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResultImageController extends AbstractController
{
    public function __construct(
        readonly private GetResultImage $getResultImage,
        readonly private LoggerInterface $logger,
        readonly private ResultAutoRemovalRepository $autoRemovalRepository,
    ) {
    }

    #[Route('/result-image/{timeId}', name: 'result_image')]
    public function __invoke(string $timeId): Response
    {
        try {
            $resultImage = $this->getResultImage->forSolvingTime($timeId);
        } catch (PuzzleSolvingTimeNotFound $exception) {
            // A shared image of a copy removed automatically shows the copy that was kept
            $keptTimeId = $this->autoRemovalRepository->findKeptTimeIdOf($timeId);

            if ($keptTimeId === null) {
                throw $exception;
            }

            return $this->redirectToRoute('result_image', ['timeId' => $keptTimeId], Response::HTTP_MOVED_PERMANENTLY);
        } catch (FilesystemException | AsyncAwsException $exception) {
            // Object storage outage (the source photo is on S3 and not in the
            // local spool) - a temporary 404 beats a 500, and must not be cached
            $this->logger->warning('Result image unavailable - object storage unreachable', [
                'exception' => $exception,
                'timeId' => $timeId,
            ]);

            return new Response('', Response::HTTP_NOT_FOUND, [
                'Cache-Control' => 'no-store',
            ]);
        }

        $headers = [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
        ];

        // Drawn over the placeholder photo: fine to show, never to keep (crawlers, browsers, CDN)
        if ($resultImage->withPlaceholder) {
            $headers['Cache-Control'] = 'no-store';
        }

        return new Response($resultImage->content, 200, $headers);
    }
}
