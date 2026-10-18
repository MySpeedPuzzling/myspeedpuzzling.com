<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DetectPlayerTimezone;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The browser's time zone, posted by timezone_detect_controller.js from the welcome screen while the new account has
 * none (docs/features/player-timezone.md) - the safety net behind the sign-up forms' hidden field. Fills an empty value
 * only (DetectPlayerTimezone), so it can never change a zone the player chose; an unknown value changes nothing.
 */
final class DetectTimezoneController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/detect-timezone',
        name: 'detect_timezone',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        $timezone = $request->request->get('timezone');

        if ($player !== null && $player->timezone === null && is_string($timezone)) {
            $this->messageBus->dispatch(new DetectPlayerTimezone($player->playerId, $timezone));
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
