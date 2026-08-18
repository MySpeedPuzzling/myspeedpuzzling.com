<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginProviders;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Value\OauthFlowIntent;
use SpeedPuzzling\Web\Value\OauthFlowState;
use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Starts a social LOGIN flow: mints the OAuth state (+ PKCE for Google) into
 * the server-side store and redirects to the provider's consent screen.
 * Deliberately session-free for anonymous visitors (#164) - everything the
 * callback needs lives in the cache-backed state payload.
 *
 * Stays reachable while SOCIAL_LOGIN_ADMIN_ONLY is on: admins test the
 * logged-out flow via this direct URL (no buttons render anywhere), and
 * enforcement happens in the callback where identity is finally known.
 */
final class SocialLoginStartController extends AbstractController
{
    public function __construct(
        private readonly SocialLoginSettings $socialLoginSettings,
        private readonly SocialLoginProviders $socialLoginProviders,
        private readonly SocialLoginStateStore $stateStore,
    ) {
    }

    #[Route(
        path: '/login/social/{provider}',
        name: 'social_login_start',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $provider): Response
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        if ($oauthProvider === null || $this->socialLoginSettings->isEnabled($oauthProvider) === false) {
            throw new NotFoundHttpException();
        }

        // Logged-in visitors belong in the settings connect flow (rule 5); for
        // Apple a failed login callback would even replace their session cookie
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('my_profile');
        }

        $leagueProvider = $this->socialLoginProviders->create($oauthProvider);
        $authorizationUrl = $leagueProvider->getAuthorizationUrl();

        $this->stateStore->storeState($leagueProvider->getState(), new OauthFlowState(
            provider: $oauthProvider,
            intent: OauthFlowIntent::Login,
            // Only Google runs PKCE; the getter returns null for the others
            pkceVerifier: $leagueProvider->getPkceCode(),
            // The login page passes on the ?return= it was given, so a social
            // sign-in lands where a password sign-in would. Validated here, at
            // the edge, so the state payload only ever holds a safe path.
            returnUrl: ReturnUrl::tryFrom($request->query->getString('return'))?->path,
        ));

        return new RedirectResponse($authorizationUrl);
    }
}
