<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A sign-in link that is expired, already used, unknown or tampered with all end
 * here — with the same message, so a replayed link tells an attacker nothing.
 * The user lands back on the request form, one click away from a fresh link
 * (the default handler would send them to /login, which during the migration
 * window is still the Auth0 redirect and would swallow the explanation).
 */
final readonly class LoginLinkFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(
                'danger',
                $this->translator->trans('auth.sign_in_link.invalid_link'),
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('sign_in_link_request'));
    }
}
