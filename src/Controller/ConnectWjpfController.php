<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\WjpfPairingState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Landing page of the manual pairing flow: WJPF sends a signed-in member here, we make sure
 * they are signed in with us too, and ask them to confirm.
 *
 * This exists because e-mail matching cannot pair everyone - a member registered under one
 * address here and another there is invisible to the bulk sync. Here the identity comes from
 * the session, so no address has to agree.
 *
 * IS_AUTHENTICATED_FULLY sends anonymous visitors through the standard ?return= login flow,
 * which brings them back to this exact URL, state intact.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ConnectWjpfController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'connect_wjpf';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        #[Autowire(env: 'trim:string:WJPF_PAIR_REDIRECT_URL')]
        readonly private string $wjpfPairRedirectUrl,
    ) {
    }

    #[Route(path: '/connect/wjpf', name: 'connect_wjpf', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Closed-by-default: with no return URL configured there is nowhere to send the
        // member afterwards, so the flow does not exist rather than dead-ending them.
        if ($this->wjpfPairRedirectUrl === '') {
            throw new NotFoundHttpException();
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('connect_wjpf.html.twig', [
            'state' => WjpfPairingState::tryFrom($request->query->getString('state'))?->value,
            'player_name' => $profile->playerName,
        ]);
    }
}
