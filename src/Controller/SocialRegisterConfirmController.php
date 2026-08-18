<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Message\RegisterWithOauthIdentity;
use SpeedPuzzling\Web\Message\SendEmailVerificationLink;
use SpeedPuzzling\Web\Security\AppleLoginAuthenticator;
use SpeedPuzzling\Web\Security\FacebookLoginAuthenticator;
use SpeedPuzzling\Web\Security\GoogleLoginAuthenticator;
use SpeedPuzzling\Web\Security\UserAccountProvider;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rule-4 interstitial (plan §Linking vs merging): the OAuth callback never
 * creates an account silently - it parks the provider profile and sends the
 * visitor here to ask "create a new account with {email}?", with a pointer to
 * "sign in first, then connect from settings" for people who already have an
 * account under another address. This is the only moment the duplicate-account
 * mistake can be prevented; true account merging is out of scope.
 *
 * The single-use parked token doubles as the CSRF guard of the confirmation
 * POST - it is unguessable and bound to exactly one parked profile.
 */
final class SocialRegisterConfirmController extends AbstractController
{
    public function __construct(
        private readonly SocialLoginSettings $socialLoginSettings,
        private readonly SocialLoginStateStore $stateStore,
        private readonly MessageBusInterface $messageBus,
        private readonly Security $security,
        private readonly UserAccountProvider $userAccountProvider,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/register/social',
        name: 'social_register_confirm',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        // Rule 4 is disabled entirely during the admin-only stage - this page
        // included (nothing can have parked a profile anyway)
        if ($this->socialLoginSettings->isAdminOnly()) {
            throw new NotFoundHttpException();
        }

        if ($this->getUser() !== null) {
            return $this->redirectToRoute('my_profile');
        }

        if ($request->isMethod('POST')) {
            return $this->confirm($request);
        }

        $parked = $this->stateStore->peekRegistration(self::tokenFrom($request));

        if ($parked === null) {
            $this->addFlash('warning', $this->translator->trans('auth.social.confirm.expired'));

            return $this->redirectToRoute('login');
        }

        return $this->render('social_register_confirm.html.twig', [
            'token' => self::tokenFrom($request),
            'email' => $parked->profile->email,
            'provider_name' => $parked->profile->provider->displayName(),
        ]);
    }

    private function confirm(Request $request): Response
    {
        $parked = $this->stateStore->consumeRegistration(self::tokenFrom($request));

        if ($parked === null) {
            $this->addFlash('warning', $this->translator->trans('auth.social.confirm.expired'));

            return $this->redirectToRoute('login');
        }

        $email = $parked->profile->email;
        assert($email !== null); // the resolver refuses to park email-less profiles

        try {
            $envelope = $this->messageBus->dispatch(new RegisterWithOauthIdentity(
                provider: $parked->profile->provider,
                providerUserId: $parked->profile->providerUserId,
                email: $email,
                emailVerified: $parked->profile->emailVerified,
                name: $parked->profile->name,
                locale: $parked->locale,
            ));
        } catch (HandlerFailedException $exception) {
            $reason = $exception->getPrevious();

            // Benign races: the address or identity got claimed between the
            // callback and this confirmation - same answer as native
            // registration (D8 accepted enumeration tradeoff)
            if (
                $reason instanceof EmailAlreadyRegistered
                || $reason instanceof OauthIdentityAlreadyLinked
                || $reason instanceof UniqueConstraintViolationException
            ) {
                $this->addFlash('warning', $this->translator->trans('auth.register.email_already_registered'));

                return $this->redirectToRoute('login');
            }

            $this->logger->error('Social registration failed.', [
                'exception' => $exception,
            ]);

            $this->addFlash('danger', $this->translator->trans('auth.register.failed'));

            return $this->redirectToRoute('login');
        }

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        $userId = $handledStamp->getResult();
        assert(is_string($userId));

        // Straight into the session, like native registration - the provider
        // just proved this identity. The authenticator must be named because
        // the main firewall carries several (see RegisterController).
        $this->security->login(
            $this->userAccountProvider->loadUserByIdentifier($userId),
            authenticatorName: match ($parked->profile->provider) {
                OauthProvider::Google => GoogleLoginAuthenticator::class,
                OauthProvider::Facebook => FacebookLoginAuthenticator::class,
                OauthProvider::Apple => AppleLoginAuthenticator::class,
            },
            firewallName: 'main',
        );

        // Google/Apple emails arrive provider-verified; the rare unverified
        // one (Facebook edge) gets the same confirmation ask as native signup
        if ($parked->profile->emailVerified === false) {
            $this->messageBus->dispatch(new SendEmailVerificationLink(
                userId: $userId,
                fallbackLocale: $request->getLocale(),
            ));
        }

        $this->addFlash('success', $this->translator->trans('auth.social.confirm.created'));

        return $this->redirectToRoute('my_profile');
    }

    private static function tokenFrom(Request $request): null|string
    {
        $token = $request->isMethod('POST')
            ? $request->request->get('token')
            : $request->query->get('token');

        return is_string($token) ? $token : null;
    }
}
