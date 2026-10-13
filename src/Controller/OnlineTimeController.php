<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The server's time in milliseconds for the clock sync of the online competitions' pages
 * (docs/features/online-competitions/README.md §5.2, assets/online/clock_sync.js): a page asks a few times at load and
 * keeps the sample with the fastest round trip. On the `stateless` firewall - no session, no cookie - and as cheap as
 * an answer gets; never stored anywhere.
 */
final class OnlineTimeController extends AbstractController
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/-/time', name: 'online_time', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse(['serverNowMs' => RoundClock::milliseconds($this->clock->now())]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
