<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Message\LinkOauthIdentity;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\ParkedSocialLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where every link flow ends: the settings connect (after the OAuth callback
 * parked the profile) and the rule-4 interstitial "sign in and connect" (after
 * the visitor signed in). Links the parked provider identity ONLY when the
 * signed-in visitor holding the token satisfies every binding of the parked
 * link (ParkedSocialLink): the account that started the flow, and/or the
 * browser that parked it (nonce cookie). Anything else - somebody else's
 * token, a replay, an expired one - is the same generic failure, and the
 * token is consumed either way.
 *
 * Linking on a GET is a deliberate exception to "GETs never change state":
 * the rule exists for e-mailed links that mail scanners prefetch. This URL is
 * never e-mailed - it is only ever reached through our own 303 from the
 * callback (or the post-login ?return=), it is single-use, and it only acts
 * for the signed-in account the parked link is bound to, so a prefetch or a
 * forged click can at worst burn a token and never link anything to the wrong
 * account. An auto-submitting POST page would add a round-trip and a CSRF
 * token without adding a binding the ones above do not already enforce.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SocialLinkFinishController extends AbstractController
{
    public const string BROWSER_BINDING_COOKIE = 'msp_social_link';

    public function __construct(
        private readonly SocialLoginStateStore $stateStore,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/connect/social/{provider}/finish/{token}',
        name: 'social_link_finish',
        requirements: ['token' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserAccount $user, string $provider, string $token): Response
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        if ($oauthProvider === null) {
            throw new NotFoundHttpException();
        }

        // Consumed before any check: a token that failed a binding is burned,
        // so it cannot be retried from another account or browser
        $parked = $this->stateStore->consumeLink($token);

        if ($parked === null || $parked->profile->provider !== $oauthProvider || !$this->bindingsHold($parked, $user, $request)) {
            if ($parked !== null) {
                $this->logger->info('Parked social link refused: bindings did not match the signed-in visitor.', [
                    'provider' => $oauthProvider->value,
                ]);
            }

            return $this->linkResult($request, $oauthProvider, 'failed');
        }

        try {
            $this->messageBus->dispatch(new LinkOauthIdentity(
                userId: $user->userId,
                provider: $oauthProvider,
                providerUserId: $parked->profile->providerUserId,
                emailAtLink: $parked->profile->email,
            ));
        } catch (HandlerFailedException | UniqueConstraintViolationException $exception) {
            // The unique (user_account_id, provider) index settles two racing
            // links at flush; the handler's own check covers the common case
            if (
                $exception instanceof UniqueConstraintViolationException
                || $exception->getPrevious() instanceof OauthIdentityAlreadyLinked
            ) {
                return $this->linkResult($request, $oauthProvider, 'already_linked');
            }

            $this->logger->error('Linking a social identity failed.', [
                'exception' => $exception,
                'provider' => $oauthProvider->value,
            ]);

            return $this->linkResult($request, $oauthProvider, 'failed');
        }

        return $this->linkResult($request, $oauthProvider, 'connected');
    }

    private function bindingsHold(ParkedSocialLink $parked, UserAccount $user, Request $request): bool
    {
        if ($parked->targetUserId !== null && $parked->targetUserId !== $user->userId) {
            return false;
        }

        if ($parked->browserBindingHash !== null) {
            $nonce = $request->cookies->get(self::BROWSER_BINDING_COOKIE);

            if (!is_string($nonce) || !hash_equals($parked->browserBindingHash, hash('sha256', $nonce))) {
                return false;
            }
        }

        return true;
    }

    private function linkResult(Request $request, OauthProvider $provider, string $result): Response
    {
        $response = $this->redirectToRoute('edit_profile', [
            'social_link_result' => $result,
            'social_link_provider' => $provider->value,
        ]);

        // The binding nonce is single-purpose; drop it whatever the outcome
        if ($request->cookies->has(self::BROWSER_BINDING_COOKIE)) {
            $response->headers->clearCookie(self::BROWSER_BINDING_COOKIE, SocialRegisterSignInController::COOKIE_PATH);
        }

        return $response;
    }
}
