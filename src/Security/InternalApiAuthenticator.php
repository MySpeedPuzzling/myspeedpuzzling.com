<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class InternalApiAuthenticator extends AbstractAuthenticator
{
    private const string HEADER_PREFIX = 'Bearer ';

    public const string USER_IDENTIFIER = 'internal-api';

    public const string ROLE = 'ROLE_INTERNAL_API';

    public function __construct(
        #[Autowire(env: 'INTERNAL_API_TOKEN')]
        private readonly string $internalApiToken,
    ) {
    }

    public function supports(Request $request): bool
    {
        // Closed-by-default: if the env var isn't configured, the firewall never authenticates,
        // so access_control denies the request rather than letting an empty token match.
        if ($this->internalApiToken === '') {
            return false;
        }

        return str_starts_with($request->headers->get('Authorization', ''), self::HEADER_PREFIX);
    }

    public function authenticate(Request $request): Passport
    {
        $submittedToken = substr($request->headers->get('Authorization', ''), strlen(self::HEADER_PREFIX));

        if (hash_equals($this->internalApiToken, $submittedToken) === false) {
            throw new CustomUserMessageAuthenticationException('Invalid internal API token.');
        }

        return new SelfValidatingPassport(
            new UserBadge(
                self::USER_IDENTIFIER,
                fn (): InMemoryUser => new InMemoryUser(self::USER_IDENTIFIER, null, [self::ROLE]),
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null|Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
