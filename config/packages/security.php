<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Auth0\Symfony\Security\UserProvider;
use SpeedPuzzling\Web\Security\Auth0EntryPoint;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use SpeedPuzzling\Web\Security\LoginLinkFailureHandler;
use SpeedPuzzling\Web\Security\LoginLinkSuccessHandler;
use SpeedPuzzling\Web\Security\OAuth2UserProvider;
use SpeedPuzzling\Web\Security\PatAuthenticator;
use SpeedPuzzling\Web\Security\UserAccountProvider;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;

return App::config([
    'security' => [
        'password_hashers' => [
            // Explicit argon2id ('auto' would pick bcrypt); migrate_from transparently
            // re-hashes imported Auth0 bcrypt ($2b$) hashes on first successful login
            \SpeedPuzzling\Web\Entity\UserAccount::class => [
                'algorithm' => 'argon2id',
                'migrate_from' => ['bcrypt'],
            ],
        ],
        'providers' => [
            'user_account_provider' => [
                'id' => UserAccountProvider::class,
            ],
            'auth0_provider' => [
                'id' => UserProvider::class,
            ],
            // Window A of the Auth0 migration (issue #147): sessions may hold either
            // a native UserAccount or an Auth0 bundle user, so both providers must be
            // able to refresh. Order is load-bearing: the Auth0 provider json_decodes
            // its identifiers (JSON blobs) and throws JsonException - not
            // UserNotFoundException - on a native "msp|..."/"auth0|..." string, so the
            // user_account provider must claim those first. Collapses to
            // user_account_provider alone in Phase 6.
            'window_a_chain_provider' => [
                'chain' => [
                    'providers' => ['user_account_provider', 'auth0_provider'],
                ],
            ],
            'oauth2_provider' => [
                'id' => OAuth2UserProvider::class,
            ],
            // Required by Symfony because the internal_api firewall must declare a provider,
            // but never actually invoked: InternalApiAuthenticator returns a SelfValidatingPassport
            // whose UserBadge closure produces the user inline. Kept as a dedicated dummy provider
            // so the config reads honestly — this firewall has its own user universe.
            'internal_api_provider' => [
                'memory' => [
                    'users' => [
                        InternalApiAuthenticator::USER_IDENTIFIER => [
                            'roles' => [InternalApiAuthenticator::ROLE],
                        ],
                    ],
                ],
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js)/',
                'security' => false,
            ],
            'stateless' => [
                // /homepage-stats: public JSON for the homepage live counters - identical
                // for every visitor, must stay session/cookie-free to be cacheable end to end.
                // /-/asset-load-failure: sendBeacon telemetry from broken clients - beacons
                // carry no cookies-worth of context and must never start a session.
                'pattern' => '^(/-/health-check|/-/asset-load-failure$|/media/cache|/sitemap|/homepage-stats$)',
                'stateless' => true,
                'security' => false,
            ],
            'api' => [
                'pattern' => '^/api/v1/',
                'stateless' => true,
                'provider' => 'oauth2_provider',
                'oauth2' => true,
                'custom_authenticators' => [PatAuthenticator::class],
            ],
            'internal_api' => [
                'pattern' => '^/internal-api/',
                'stateless' => true,
                'provider' => 'internal_api_provider',
                'custom_authenticators' => [InternalApiAuthenticator::class],
            ],
            'main' => [
                'pattern' => '^/',
                'lazy' => true,
                'provider' => 'window_a_chain_provider',
                // Window-A dual wiring: the native login POST is handled by
                // LoginFormAuthenticator (only supports POST /login), everything else
                // still authenticates from the Auth0 session. The Auth0 authenticator
                // returns a null failure response on public pages, so it never
                // short-circuits the chain for anonymous visitors. Entry point stays
                // Auth0 until Stage B (native_login flag).
                'custom_authenticators' => [LoginFormAuthenticator::class, 'auth0.authenticator'],
                // Magic sign-in link, live from Stage A (D6): the rescue for users whose
                // password manager filed the credential under the Auth0 domain, and for
                // window-A native registrants who log out while /login still points at
                // Auth0. Single-use is added by SingleUseLoginLinkHandler (D18), which
                // decorates the handler this factory registers - Symfony's own max_uses
                // needs a PSR-6 pool and cannot express "issued by us".
                'login_link' => [
                    'check_route' => 'sign_in_link_check',
                    // The link dies when the address it was sent to changes, and when the
                    // password changes (a reset must not leave older links usable)
                    'signature_properties' => ['email', 'password'],
                    'lifetime' => '%signInLinkLifetimeSeconds%',
                    // Never the Auth0 provider: it json_decodes identifiers and would throw
                    // JsonException instead of UserNotFoundException on a native user_id
                    'provider' => 'user_account_provider',
                    'success_handler' => LoginLinkSuccessHandler::class,
                    'failure_handler' => LoginLinkFailureHandler::class,
                ],
                // Deliberately NO remember_me until Phase 6: Symfony's RememberMeListener
                // clears the cookie on EVERY LoginFailureEvent unconditionally, and during
                // the migration window the Auth0 authenticator fails on every anonymous
                // request - a REMEMBERME deletion cookie would land on every anonymous
                // response and break shared cacheability (#164). Enable it (signature-based,
                // user_providers: [user_account_provider], always_remember_me) when the
                // Auth0 authenticator leaves this firewall. The RememberMeBadge already in
                // LoginFormAuthenticator is inert meanwhile.
                //
                // Deliberately NO firewall-level login_throttling either, same interplay:
                // its failure listener would count those per-request anonymous failures
                // against the per-IP budget. Brute-force protection lives inside
                // LoginFormAuthenticator (5/min per email+IP + per-IP limiter,
                // config/packages/rate_limiter.php) and stays there.
                'entry_point' => Auth0EntryPoint::class,
                'logout' => [
                    'path' => 'app_logout',
                    'target' => '/',
                ],
            ],
        ],
        'access_control' => [
            [
                'path' => '^/api/docs',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/internal-api/',
                'roles' => [InternalApiAuthenticator::ROLE],
            ],
            [
                'path' => '^/api/v1/me',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            [
                'path' => '^/api/v1/players/.*/results',
                'roles' => ['ROLE_OAUTH2_RESULTS:READ'],
            ],
            [
                'path' => '^/api/v1/players/.*/statistics',
                'roles' => ['ROLE_OAUTH2_STATISTICS:READ'],
            ],
            [
                'path' => '^/api/v1/players/.*/collections',
                'roles' => ['ROLE_OAUTH2_COLLECTIONS:READ'],
            ],
            [
                'path' => '^/api/v1/competitions',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            [
                'path' => '^/admin',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            [
                'path' => '^/',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
        ],
    ],
]);
