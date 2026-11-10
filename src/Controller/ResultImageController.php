<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Services\GetResultImage;
use SpeedPuzzling\Web\Services\ResultImageCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

final class ResultImageController extends AbstractController
{
    /**
     * The card is opened on the recap page and shared from there or from the player's own menus: the browser keeps it
     * for an hour, then asks again (ETag) - never longer than the server keeps it (ResultImageCache::KEEP_HOURS)
     */
    private const int BROWSER_KEEPS_SECONDS = 3600;

    public function __construct(
        readonly private GetResultImage $getResultImage,
        readonly private ResultAutoRemovalRepository $autoRemovalRepository,
        readonly private ResultImageCache $resultImageCache,
    ) {
    }

    #[Route('/result-image/{timeId}', name: 'result_image')]
    public function __invoke(Request $request, string $timeId): Response
    {
        try {
            $resultImage = $this->getResultImage->forSolvingTime($timeId);
        } catch (PuzzleSolvingTimeNotFound $exception) {
            // A shared image of a copy removed automatically shows the copy that was kept
            $keptTimeId = $this->autoRemovalRepository->findKeptTimeIdOf($timeId);

            if ($keptTimeId === null) {
                throw $exception;
            }

            // Temporary: Undo brings the removed id back, so no cache may remember the redirect for good
            return $this->redirectToRoute('result_image', ['timeId' => $keptTimeId], Response::HTTP_FOUND);
        }

        $headers = [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
        ];

        // Drawn over the placeholder photo: fine to show, never to keep (crawlers, browsers, CDN)
        if ($resultImage->withPlaceholder || $resultImage->drawnAt === null) {
            $headers['Cache-Control'] = 'no-store';

            return new Response($resultImage->content, 200, $headers);
        }

        $response = new Response($resultImage->content, 200, $headers);
        // Private: only the browser that asked keeps it - a shared cache could keep serving a named card after the
        // profile turned private (that switch changes the card at once on the server)
        $response->setPrivate();
        $response->setMaxAge(min(
            self::BROWSER_KEEPS_SECONDS,
            $this->resultImageCache->secondsKeptAfter($resultImage->drawnAt),
        ));
        $response->setEtag(hash('xxh128', $resultImage->content));
        $response->setLastModified($resultImage->drawnAt);
        // The card is the same for whoever asks (it follows the player's own setting, never the viewer), so a session
        // the request touched must not turn the caching off (Symfony's session listener would: max-age=0)
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->isNotModified($request);

        return $response;
    }
}
