<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Value\ReturnUrl;
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
 * instead of the old sign-in domain. Everybody else goes where they were headed
 * when they asked for the link (the login page's ?return=, booked with the link
 * by SingleUseLoginLinkHandler), or to the profile.
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

        // Validated again on the way out: the booked value came from a form field
        $returnPath = $request->attributes->get(SingleUseLoginLinkHandler::RETURN_PATH_ATTRIBUTE);
        $returnUrl = ReturnUrl::tryFrom(is_string($returnPath) ? $returnPath : null);

        if ($returnUrl !== null) {
            return new RedirectResponse($returnUrl->path);
        }

        return new RedirectResponse($this->urlGenerator->generate('my_profile'));
    }
}
