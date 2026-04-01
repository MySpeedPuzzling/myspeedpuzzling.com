<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Repository\PersonalAccessTokenRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class PatAuthenticator extends AbstractAuthenticator
{
    private const string TOKEN_PREFIX = 'msp_pat_';

    public function __construct(
        private readonly PersonalAccessTokenRepository $personalAccessTokenRepository,
    ) {
    }

    public function supports(Request $request): bool
    {
        $authorization = $request->headers->get('Authorization', '');

        return str_starts_with($authorization, 'Token ' . self::TOKEN_PREFIX);
    }

    public function authenticate(Request $request): Passport
    {
        $authorization = $request->headers->get('Authorization', '');
        $token = substr($authorization, 6); // Remove "Token " prefix
        $tokenHash = hash('sha256', $token);

        $pat = $this->personalAccessTokenRepository->findActiveByTokenHash($tokenHash);

        if ($pat === null) {
            throw new CustomUserMessageAuthenticationException('Invalid personal access token.');
        }

        $pat->updateLastUsedAt();

        return new SelfValidatingPassport(
            new UserBadge($pat->player->id->toString(), function () use ($pat): PatUser {
                return new PatUser($pat->player);
            }),
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
