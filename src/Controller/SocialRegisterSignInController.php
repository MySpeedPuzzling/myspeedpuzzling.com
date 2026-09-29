<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Value\ParkedSocialLink;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rule-4 interstitial's "I already have an account" answer. Instead of
 * dropping the provider profile and making the visitor redo the whole dance
 * from settings, the profile stays parked: the visitor signs in to their
 * existing account (any method) and lands on the link finish route, which
 * connects the provider to whichever account they signed in to.
 *
 * The account is not known yet, so the parked link is bound to THIS browser
 * instead: a random nonce goes into a short-lived HttpOnly cookie, its hash
 * into the parked link. Without that binding the finish URL would be a
 * forged-connect primitive - an attacker could park their own Google profile
 * here and get a signed-in victim to open the URL, handing the attacker a way
 * into the victim's account.
 *
 * The parked registration token doubles as the CSRF guard of this POST, as on
 * the confirmation form next to it.
 */
final class SocialRegisterSignInController extends AbstractController
{
    public const string COOKIE_PATH = '/connect/social';

    private const int COOKIE_LIFETIME_SECONDS = 600;

    public function __construct(
        private readonly SocialLoginSettings $socialLoginSettings,
        private readonly SocialLoginStateStore $stateStore,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/register/social/sign-in',
        name: 'social_register_sign_in',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        // Rule 4 (and so this page) does not exist during the admin-only stage
        if ($this->socialLoginSettings->isAdminOnly()) {
            throw new NotFoundHttpException();
        }

        if ($this->getUser() !== null) {
            return $this->redirectToRoute('my_profile');
        }

        $token = $request->request->get('token');
        $parked = $this->stateStore->consumeRegistration(is_string($token) ? $token : null);

        if ($parked === null) {
            $this->addFlash('warning', $this->translator->trans('auth.social.confirm.expired'));

            return $this->redirectToRoute('login');
        }

        $nonce = bin2hex(random_bytes(32));

        $linkToken = $this->stateStore->parkLink(new ParkedSocialLink(
            profile: $parked->profile,
            targetUserId: null,
            browserBindingHash: hash('sha256', $nonce),
        ));

        $finishPath = $this->generateUrl('social_link_finish', [
            'provider' => $parked->profile->provider->value,
            'token' => $linkToken,
        ]);

        // Validated like every other ?return= (docs/features/return-url.md) -
        // it is our own path, but it becomes a post-login Location header
        $returnUrl = ReturnUrl::tryFrom($finishPath);
        assert($returnUrl !== null);

        $response = $this->redirectToRoute('login', ['return' => $returnUrl->path]);
        $response->headers->setCookie(Cookie::create(
            name: SocialLinkFinishController::BROWSER_BINDING_COOKIE,
            value: $nonce,
            expire: time() + self::COOKIE_LIFETIME_SECONDS,
            path: self::COOKIE_PATH,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
