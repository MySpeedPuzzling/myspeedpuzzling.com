<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class StopwatchController extends AbstractController
{
    #[Route(path: '/stopky', name: 'stopwatch', methods: ['GET'])]
    public function __invoke(null|string $stopwatchId): Response
    {
        return $this->render('stopwatch.html.twig', [
            'stopwatch' => null,
            'hours_elapsed' => '0',
            'minutes_elapsed' => '00',
            'seconds_elapsed' => '00',
        ]);
    }
}
