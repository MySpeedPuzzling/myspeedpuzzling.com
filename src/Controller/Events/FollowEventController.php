<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Not /{_locale}/events/follow: event_detail (/en/events/{slug}) has no method restriction and would take it.
 */
final class FollowEventController extends AbstractEventFollowController
{
    #[Route(
        path: '/{_locale}/follow-event',
        name: 'event_follow',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        return $this->handle($request, true);
    }
}
