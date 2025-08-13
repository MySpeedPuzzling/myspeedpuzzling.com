<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthCheckLivenessController extends AbstractController
{
    #[Route(path: '/-/health-check/liveness')]
    public function __invoke(): Response
    {
        return $this->json([
            'status' => 'ok',
            'time' => time(),
        ]);
    }
}
