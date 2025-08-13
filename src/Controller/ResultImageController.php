<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\GetResultImage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResultImageController extends AbstractController
{
    public function __construct(
        readonly private GetResultImage $getResultImage,
    ) {
    }

    #[Route('/result-image/{timeId}', name: 'result_image')]
    public function __invoke(string $timeId): Response
    {
        $fileContent = $this->getResultImage->forSolvingTime($timeId);

        return new Response($fileContent, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
        ]);
    }
}
