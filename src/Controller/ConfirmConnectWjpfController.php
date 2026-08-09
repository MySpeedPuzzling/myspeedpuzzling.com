<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Wjpf\WjpfPairingCodeStore;
use SpeedPuzzling\Web\Value\WjpfPairingState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consent step of the manual pairing flow: issues a single-use code and hands the member back
 * to WJPF with it.
 *
 * The code, not the player id, is what travels in the URL - see {@see WjpfPairingCodeStore}.
 * The return URL is configuration, never taken from the request: accepting a redirect target
 * from the caller would make this an open redirect, and this page sits right after a login
 * prompt, which is the worst place for one.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ConfirmConnectWjpfController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private WjpfPairingCodeStore $wjpfPairingCodeStore,
        #[Autowire(env: 'trim:string:WJPF_PAIR_REDIRECT_URL')]
        readonly private string $wjpfPairRedirectUrl,
    ) {
    }

    #[Route(path: '/connect/wjpf', name: 'connect_wjpf_confirm', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->wjpfPairRedirectUrl === '') {
            throw new NotFoundHttpException();
        }

        if ($this->isCsrfTokenValid(ConnectWjpfController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            throw $this->createAccessDeniedException();
        }

        $state = WjpfPairingState::tryFrom($request->request->getString('state'));

        if ($request->request->getString('action') === 'cancel') {
            // Mirrors the OAuth convention so their side can tell a refusal from a failure.
            return $this->redirect($this->buildRedirectUrl(['error' => 'access_denied'], $state));
        }

        return $this->redirect($this->buildRedirectUrl(
            ['code' => $this->wjpfPairingCodeStore->issue($profile->playerId)],
            $state,
        ));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function buildRedirectUrl(array $parameters, null|WjpfPairingState $state): string
    {
        if ($state !== null) {
            $parameters['state'] = $state->value;
        }

        // The configured URL already carries ?accion=msp_pair_redirect, so append rather
        // than assume - a hardcoded '?' would silently break the action parameter.
        $separator = str_contains($this->wjpfPairRedirectUrl, '?') ? '&' : '?';

        return $this->wjpfPairRedirectUrl . $separator . http_build_query($parameters);
    }
}
