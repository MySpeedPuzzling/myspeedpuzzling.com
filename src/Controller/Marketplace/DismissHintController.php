<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Marketplace;

use SpeedPuzzling\Web\Message\DismissHint;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DismissHintController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zavreni-napovedy',
            'en' => '/en/dismiss-hint',
            'es' => '/es/dismiss-hint',
            'ja' => '/ja/dismiss-hint',
            'fr' => '/fr/dismiss-hint',
            'de' => '/de/dismiss-hint',
        ],
        name: 'dismiss_hint',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $type = HintType::tryFrom((string) $request->request->get('type'));
        if ($type === null) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->messageBus->dispatch(new DismissHint(
            playerId: $loggedPlayer->playerId,
            type: $type,
        ));

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
