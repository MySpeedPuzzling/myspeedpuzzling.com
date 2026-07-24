<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\UserAccount;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Where a magic sign-in link lands (UX funnel §5, issue #147): users who came
 * from Auth0 are offered a one-time, skippable "set a fresh password" prompt, so
 * their password manager finally stores the credential under myspeedpuzzling.com
 * instead of the old sign-in domain. Everybody else goes straight to the profile.
 */
final readonly class LoginLinkSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();

        if ($user instanceof UserAccount && $user->legacyAuth0) {
            // Consumed by the prompt controller - the prompt is offered exactly once,
            // right after the link login, and never becomes a standing "set password
            // without knowing the old one" door
            $request->getSession()->set(SignInLinkPasswordPrompt::SESSION_KEY, true);

            return new RedirectResponse(
                $this->urlGenerator->generate('set_password_after_sign_in_link'),
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('my_profile'));
    }
}
