<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ScanController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/scan-puzzli',
            'en' => '/en/scan-puzzle',
        ],
        name: 'scan',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('scan.html.twig');
    }
}
