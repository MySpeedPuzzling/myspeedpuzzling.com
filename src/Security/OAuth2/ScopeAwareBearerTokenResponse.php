<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security\OAuth2;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use SensitiveParameter;

/**
 * Adds the granted "scope" to the token response. league/oauth2-server leaves it
 * out, yet RFC 6749 §5.1 requires it whenever the granted scope differs from the
 * requested one - which happens here: a request without "scope" is granted every
 * scope the client holds, and client_credentials tokens have their user-context
 * scopes stripped (OAuth2ClientCredentialsScopeSubscriber). Wired in via
 * league_oauth2_server.authorization_server.response_type_class.
 */
final class ScopeAwareBearerTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(
        #[SensitiveParameter]
        AccessTokenEntityInterface $accessToken,
    ): array {
        $scopes = array_map(
            static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $accessToken->getScopes(),
        );

        return ['scope' => implode(' ', $scopes)];
    }
}
