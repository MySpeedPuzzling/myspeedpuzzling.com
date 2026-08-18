<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Message\LinkOauthIdentity;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Services\SocialLogin\SocialProfileFetcher;
use SpeedPuzzling\Web\Value\OauthFlowIntent;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The shared OAuth callback route. LOGIN-intent callbacks never reach this
 * controller - the per-provider authenticators intercept them at the firewall.
 * What lands here is the LINK flow (rule 5) plus expired/invalid states.
 *
 * Deliberately session-free: Apple's cross-site POST arrives without the
 * session cookie, and writing a flash would mint a NEW session whose cookie
 * replaces the logged-in one - logging the user out as a side effect of
 * connecting a provider. Feedback travels as query parameters instead, which
 * the edit-profile page renders.
 */
final class SocialLoginCallbackController extends AbstractController
{
    public function __construct(
        private readonly SocialLoginSettings $socialLoginSettings,
        private readonly SocialLoginStateStore $stateStore,
        private readonly SocialProfileFetcher $profileFetcher,
        private readonly MessageBusInterface $messageBus,
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

        if ($oauthProvider === null || $this->socialLoginSettings->isEnabled($oauthProvider) === false) {
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

        try {
            $this->messageBus->dispatch(new LinkOauthIdentity(
                userId: $flowState->userId,
                provider: $oauthProvider,
                providerUserId: $profile->providerUserId,
                emailAtLink: $profile->email,
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof OauthIdentityAlreadyLinked) {
                return $this->linkResult($oauthProvider, 'already_linked');
            }

            $this->logger->error('Linking a social identity failed.', [
                'exception' => $exception,
                'provider' => $oauthProvider->value,
            ]);

            return $this->linkResult($oauthProvider, 'failed');
        }

        return $this->linkResult($oauthProvider, 'connected');
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
