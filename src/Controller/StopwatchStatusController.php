<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class StopwatchStatusController extends AbstractController
{
    public function __construct(
        private readonly GetStopwatch $getStopwatch
    )
    {
    }

    #[Route(path: '/stopky/{stopwatchId}', name: 'stopwatch_status', methods: ['GET'])]
    public function __invoke(string $stopwatchId): Response
    {
        $stopwatchDetail = $this->getStopwatch->byId($stopwatchId);

        $interval = $stopwatchDetail->totalSeconds;

        if ($stopwatchDetail->lastEnd === null) {
            $interval += (new \DateTimeImmutable())->getTimestamp() - $stopwatchDetail->lastStart->getTimestamp();
        }

        return $this->render('stopwatch.html.twig', [
            'stopwatch' => $stopwatchDetail,
            'hours_elapsed' => floor($interval / 3600),
            'minutes_elapsed' => str_pad((string) floor(($interval / 60) % 60), 2, '0', STR_PAD_LEFT),
            'seconds_elapsed' => str_pad((string) ($interval % 60), 2, '0', STR_PAD_LEFT),
        ]);
    }
}
