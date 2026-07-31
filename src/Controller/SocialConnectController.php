<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginProviders;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Value\OauthFlowIntent;
use SpeedPuzzling\Web\Value\OauthFlowState;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Starts an explicit LINK flow from "Connected sign-in methods" (rule 5): the
 * user is authenticated, so ownership is already proven and NO provider-email
 * match is required - a provider account under a completely different address
 * links fine (plan §Linking vs merging; this is the primary defense against
 * duplicate accounts). The target account travels in the state payload because
 * Apple's cross-site POST callback arrives without session cookies.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SocialConnectController extends AbstractController
{
    public function __construct(
        private readonly SocialLoginSettings $socialLoginSettings,
        private readonly SocialLoginProviders $socialLoginProviders,
        private readonly SocialLoginStateStore $stateStore,
    ) {
    }

    #[Route(
        path: '/account/social/{provider}/connect',
        name: 'social_link_start',
        methods: ['GET'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $provider): Response
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        if ($oauthProvider === null || $this->socialLoginSettings->isEnabled($oauthProvider) === false) {
            throw new NotFoundHttpException();
        }

        // Admin-only stage: 404, not 403 - the feature must not reveal itself
        if ($this->socialLoginSettings->isAdminOnly() && $this->isGranted(AdminAccessVoter::ADMIN_ACCESS) === false) {
            throw new NotFoundHttpException();
        }

        // A legacy Auth0 session (window A) has no user_account to link to
        if (!$user instanceof UserAccount) {
            return $this->redirectToRoute('edit_profile');
        }

        $leagueProvider = $this->socialLoginProviders->create($oauthProvider);
        $authorizationUrl = $leagueProvider->getAuthorizationUrl();

        $this->stateStore->storeState($leagueProvider->getState(), new OauthFlowState(
            provider: $oauthProvider,
            intent: OauthFlowIntent::Link,
            pkceVerifier: $leagueProvider->getPkceCode(),
            userId: $user->userId,
        ));

        return new RedirectResponse($authorizationUrl);
    }
}
