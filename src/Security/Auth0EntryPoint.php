<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class Auth0EntryPoint implements AuthenticationEntryPointInterface
{
    public const string REDIRECT_COOKIE = 'auth0_redirect_target';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, null|AuthenticationException $authException = null): Response
    {
        $response = new RedirectResponse($this->urlGenerator->generate('login'));

        // Use cookie instead of session - more reliable across OAuth redirects
        $response->headers->setCookie(
            Cookie::create(self::REDIRECT_COOKIE)
                ->withValue($request->getUri())
                ->withExpires(time() + 3600)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite('lax'),
        );

        return $response;
    }
}
