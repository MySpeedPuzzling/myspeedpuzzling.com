<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ScanController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/scan-puzzli',
            'en' => '/en/scan-puzzle',
        ],
        name: 'scan',
        methods: ['GET'],
    )]
    #[Route(
        path: [
            'cs' => '/scan-puzzli/{code}',
            'en' => '/en/scan-puzzle/{code}',
        ],
        name: 'scan_puzzle',
        methods: ['GET'],
    )]
    public function __invoke(null|string $code): Response
    {
        if ($code !== null) {
            try {
                $puzzle = $this->getPuzzleOverview->byEan($code);

                return $this->forward('puzzle_detail', [
                    'puzzleId' => $puzzle->puzzleId,
                ]);
            } catch (PuzzleNotFound) {
                // Do nothing -> not found - we probably do not have these puzzle in database
            }
        }

        return $this->render('scan.html.twig', [
            'code' => $code,
        ]);
    }
}
