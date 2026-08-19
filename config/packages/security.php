<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Auth0\Symfony\Security\UserProvider;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\AppleLoginAuthenticator;
use SpeedPuzzling\Web\Security\LoginEntryPoint;
use SpeedPuzzling\Web\Security\FacebookLoginAuthenticator;
use SpeedPuzzling\Web\Security\GoogleLoginAuthenticator;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use SpeedPuzzling\Web\Security\LoginLinkFailureHandler;
use SpeedPuzzling\Web\Security\LoginLinkSuccessHandler;
use SpeedPuzzling\Web\Security\MigrationWindowAuth0Authenticator;
use SpeedPuzzling\Web\Security\OAuth2User;
use SpeedPuzzling\Web\Security\OAuth2UserProvider;
use SpeedPuzzling\Web\Security\PatAuthenticator;
use SpeedPuzzling\Web\Security\PatUser;
use SpeedPuzzling\Web\Security\UserAccountProvider;
use SpeedPuzzling\Web\Value\OAuth2Scope;
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
                // /api/v0/wjpf-pairing: server-to-server call from worldjigsawpuzzle.org,
                // authenticated by its own static token in the request - no user, no session.
                'pattern' => '^(/-/health-check|/-/asset-load-failure$|/media/cache|/sitemap|/homepage-stats$|/api/v0/wjpf-pairing$)',
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
                // is wrapped by MigrationWindowAuth0Authenticator so that its
                // per-request failure (every native session fails it) can never
                // short-circuit the request with a redirect to /login - neither for
                // anonymous visitors nor, as it used to on every non-public
                // access_control pattern, for signed-in ones. Entry point stays
                // Auth0 until Stage B (native_login flag).
                // The social authenticators (auth hardening PR 2) claim only their
                // own /login/social/{provider}/callback with a login-intent state;
                // per-provider feature flags gate them inside supports(). They sit
                // before the Auth0 entry so its anonymous null-failure never
                // preempts a social callback.
                'custom_authenticators' => [
                    LoginFormAuthenticator::class,
                    GoogleLoginAuthenticator::class,
                    FacebookLoginAuthenticator::class,
                    AppleLoginAuthenticator::class,
                    MigrationWindowAuth0Authenticator::class,
                ],
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
                // Always-on sliding 30-day login, no "remember me" checkbox: every
                // successful sign-in (password, social, magic link) mints a signed
                // cookie, and SignatureRememberMeHandler re-issues it with a fresh
                // 30-day window each time it is consumed - an active user is never
                // signed out, an idle one after 30 days.
                //
                // This is only safe because RememberMeMigrationWindowPass swaps the
                // firewall's remember-me listener for MigrationWindowRememberMeListener:
                // core's clears the cookie on EVERY LoginFailureEvent unconditionally,
                // and the window-era Auth0 authenticator fails on every request, which
                // would both stamp a deletion cookie on anonymous responses (breaking
                // #164 shared cacheability) and delete a signed-in user's cookie on
                // their next page view. Both listener and pass die in Phase 6.
                'remember_me' => [
                    'lifetime' => 2592000, // 30 days, slides on every use
                    // No checkbox in the login form - being kept signed in is the
                    // default for everyone (product decision, 2026-08-01)
                    'always_remember_me' => true,
                    // Signature-based (no token table): the cookie dies when the
                    // password or the address it belongs to changes, so a password
                    // reset or email change signs every device out. Mirrors the
                    // login_link signature above. Null passwords (social-only
                    // accounts) hash as '' - supported by SignatureHasher.
                    'signature_properties' => ['email', 'password'],
                    //
                    // The user provider is deliberately NOT set here - it cannot be.
                    // RememberMeFactory does not extend AbstractFactory, so it has no
                    // 'provider' node, and its own 'user_providers' node is a leftover
                    // from the pre-authenticator system that is silently ignored. Left
                    // alone, the handler inherits this firewall's chain provider, whose
                    // Auth0 half json_decodes identifiers and throws JsonException - not
                    // UserNotFoundException - on a native "msp|..." string, i.e. a 500
                    // for anyone holding a cookie for a deleted account.
                    // RememberMeMigrationWindowPass pins user_account_provider instead.
                    //
                    // 'auto' = secure whenever the request is HTTPS, matching the
                    // session cookie. Hard true would silently disable remember-me on
                    // plain-HTTP dev and in the functional test client.
                    'secure' => 'auto',
                    'samesite' => 'lax',
                    'httponly' => true,
                    'path' => '/',
                ],
                // Deliberately NO firewall-level login_throttling either, same interplay:
                // its failure listener would count those per-request anonymous failures
                // against the per-IP budget. Brute-force protection lives inside
                // LoginFormAuthenticator (5/min per email+IP + per-IP limiter,
                // config/packages/rate_limiter.php) and stays there.
                'entry_point' => LoginEntryPoint::class,
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
            // "Me" endpoints need a user behind the token: a PAT, or an OAuth2
            // token issued through the authorization-code flow. A client_credentials
            // token is authenticated too (as the bundle's ClientCredentialsUser, no
            // roles) so IS_AUTHENTICATED_FULLY let it through to providers that
            // assert an ApiUser - and it died with a 500 instead of this 403.
            [
                'path' => '^/api/v1/me',
                'roles' => [PatUser::ROLE, OAuth2User::ROLE],
            ],
            // The public profile of a player (GET /players/{id}): profile:read, any
            // OAuth2 token - a client_credentials token sees public profile data
            // too. The regex ends at the id segment so it cannot shadow the
            // per-scope rules for /players/{id}/results|statistics|collections.
            [
                'path' => '^/api/v1/players/[^/]+/?$',
                'roles' => [OAuth2Scope::ProfileRead->role()],
            ],
            [
                'path' => '^/api/v1/players/.*/results',
                'roles' => [OAuth2Scope::ResultsRead->role()],
            ],
            [
                'path' => '^/api/v1/players/.*/statistics',
                'roles' => [OAuth2Scope::StatisticsRead->role()],
            ],
            [
                'path' => '^/api/v1/players/.*/collections',
                'roles' => [OAuth2Scope::CollectionsRead->role()],
            ],
            [
                'path' => '^/api/v1/competitions',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            // The puzzle catalog is public data, but never an anonymous API: any
            // valid token (PAT, auth-code, client_credentials) - members-only parts
            // of the response are gated per token owner inside the providers.
            [
                'path' => '^/api/v1/puzzles',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            // Admin access, not merely "signed in": every controller under
            // src/Controller/Admin also carries #[IsGranted(ADMIN_ACCESS)], but a
            // firewall-level rule is what covers the one somebody forgets to
            // annotate. IS_AUTHENTICATED_FULLY would be both weaker (any signed-in
            // visitor passes it) and wrong under always-on remember-me: a visitor
            // signed back in from the 30-day cookie holds a RememberMeToken, which
            // is not "full fledged", so they were sent to /login - and, being
            // signed in already, straight on to my_profile from there.
            [
                'path' => '^/admin',
                'roles' => [AdminAccessVoter::ADMIN_ACCESS],
            ],
            [
                'path' => '^/',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
        ],
    ],
]);
