<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security\OAuth2;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

final class ScopeAwareBearerTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(
            static fn ($scope): string => $scope->getIdentifier(),
            $accessToken->getScopes(),
        );

        return ['scope' => implode(' ', $scopes)];
    }
}
