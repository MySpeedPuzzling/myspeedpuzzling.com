<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use League\Bundle\OAuth2ServerBundle\Event\ScopeResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use SpeedPuzzling\Web\Value\OAuth2Scope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps user-context scopes (solving-times:write, collections:write) off
 * client_credentials tokens, as the developer docs promise ("auth code only").
 *
 * The bundle finalises scopes purely against the client's own scope list, and
 * with no "scope" parameter it hands out *everything* the client holds - so a
 * client approved for write scopes would otherwise mint machine tokens that
 * carry them. Rather than failing the whole token request (which would break
 * every parameter-less client_credentials call such a client already makes),
 * the scopes are silently dropped: RFC 6749 §3.3 allows the server to narrow
 * the grant, and the token response's "scope" field tells the client what it
 * actually got.
 */
final readonly class OAuth2ClientCredentialsScopeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            OAuth2Events::SCOPE_RESOLVE => 'onScopeResolve',
        ];
    }

    public function onScopeResolve(ScopeResolveEvent $event): void
    {
        if ((string) $event->getGrant() !== OAuth2Grants::CLIENT_CREDENTIALS) {
            return;
        }

        $allowed = array_values(array_filter(
            $event->getScopes(),
            static function (Scope $scope): bool {
                $known = OAuth2Scope::tryFrom((string) $scope);

                // Unknown scopes are the bundle's business - leave them alone.
                return $known === null || $known->requiresUserContext() === false;
            },
        ));

        $event->setScopes(...$allowed);
    }
}
