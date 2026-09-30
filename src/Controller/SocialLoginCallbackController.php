<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Services\SocialLogin\SocialProfileFetcher;
use SpeedPuzzling\Web\Value\OauthFlowIntent;
use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\ParkedSocialLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The shared OAuth callback route. LOGIN-intent callbacks never reach this
 * controller - the per-provider authenticators intercept them at the firewall.
 * What lands here is the LINK flow (rule 5) plus expired/invalid states.
 *
 * This controller never links. It cannot know WHO finishes the flow - Apple's
 * cross-site POST arrives without any cookie - so linking here would let an
 * attacker start a connect flow from THEIR account and trick a victim into
 * completing the consent (forged connect: the victim's Google/Apple/Facebook
 * lands on the attacker's account, and the victim's next "Continue with
 * Google" signs them into it). Instead it parks the provider-proven profile
 * together with the account that started the flow and 303-redirects to the
 * finish route, where the signed-in visitor must be that same account
 * (SocialLinkFinishController). The redirect turns Apple's POST into a
 * top-level GET, which does carry the SameSite=Lax session/remember-me cookies.
 *
 * Deliberately session-free: writing a flash here would mint a NEW session
 * whose cookie replaces the logged-in one on Apple's cookie-less POST. Early
 * failures travel as query parameters, which the edit-profile page renders.
 */
final class SocialLoginCallbackController extends AbstractController
{
    public function __construct(
        private readonly SocialLoginSettings $socialLoginSettings,
        private readonly SocialLoginStateStore $stateStore,
        private readonly SocialProfileFetcher $profileFetcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/login/social/{provider}/callback',
        name: 'social_login_callback',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $provider): Response
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        if ($oauthProvider === null || $this->socialLoginSettings->isAvailable($oauthProvider) === false) {
            throw new NotFoundHttpException();
        }

        $state = $request->query->get('state') ?? $request->request->get('state');
        $flowState = $this->stateStore->consumeState(is_string($state) ? $state : null);

        if ($flowState === null || $flowState->provider !== $oauthProvider || $flowState->intent !== OauthFlowIntent::Link || $flowState->userId === null) {
            // Expired, replayed or foreign state - the login page explains via
            // the query flag (no session write, see the class comment)
            return $this->redirectToRoute('login', ['social' => 'expired']);
        }

        $providerError = $request->query->get('error') ?? $request->request->get('error');

        if (is_string($providerError) && $providerError !== '') {
            return $this->linkResult($oauthProvider, 'cancelled');
        }

        $code = $request->query->get('code') ?? $request->request->get('code');

        if (!is_string($code) || $code === '') {
            return $this->linkResult($oauthProvider, 'failed');
        }

        try {
            $profile = $this->profileFetcher->fetch(
                $oauthProvider,
                $code,
                $flowState->pkceVerifier,
                self::appleUserPayload($request),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Social link code exchange failed.', [
                'exception' => $exception,
                'provider' => $oauthProvider->value,
            ]);

            return $this->linkResult($oauthProvider, 'failed');
        }

        $token = $this->stateStore->parkLink(new ParkedSocialLink(
            profile: $profile,
            targetUserId: $flowState->userId,
            browserBindingHash: null,
        ));

        return new RedirectResponse(
            $this->generateUrl('social_link_finish', ['provider' => $oauthProvider->value, 'token' => $token]),
            Response::HTTP_SEE_OTHER,
        );
    }

    private function linkResult(OauthProvider $provider, string $result): Response
    {
        return $this->redirectToRoute('edit_profile', [
            'social_link_result' => $result,
            'social_link_provider' => $provider->value,
        ]);
    }

    private static function appleUserPayload(Request $request): null|string
    {
        $user = $request->request->get('user');

        return is_string($user) && $user !== '' ? $user : null;
    }
}
