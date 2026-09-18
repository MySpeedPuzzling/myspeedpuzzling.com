<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\AppleLoginAuthenticator;
use SpeedPuzzling\Web\Security\LoginEntryPoint;
use SpeedPuzzling\Web\Security\FacebookLoginAuthenticator;
use SpeedPuzzling\Web\Security\GoogleLoginAuthenticator;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use SpeedPuzzling\Web\Security\LoginLinkFailureHandler;
use SpeedPuzzling\Web\Security\LoginLinkSuccessHandler;
use SpeedPuzzling\Web\Security\OAuth2User;
use SpeedPuzzling\Web\Security\OAuth2UserProvider;
use SpeedPuzzling\Web\Security\PatAuthenticator;
use SpeedPuzzling\Web\Security\PatUser;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
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
                // /-/asset-load-failure, /-/stale-document: sendBeacon telemetry from broken
                // clients - beacons carry no cookies-worth of context and must never start a session.
                // /api/v0/wjpf-pairing: server-to-server call from worldjigsawpuzzle.org,
                // authenticated by its own static token in the request - no user, no session.
                'pattern' => '^(/-/health-check|/-/asset-load-failure$|/-/stale-document$|/media/cache|/sitemap|/homepage-stats$|/api/v0/wjpf-pairing$)',
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
                'provider' => 'user_account_provider',
                // LoginFormAuthenticator only supports POST /login. The social
                // authenticators (auth hardening PR 2) claim only their own
                // /login/social/{provider}/callback with a login-intent state;
                // per-provider feature flags gate them inside supports().
                'custom_authenticators' => [
                    LoginFormAuthenticator::class,
                    GoogleLoginAuthenticator::class,
                    FacebookLoginAuthenticator::class,
                    AppleLoginAuthenticator::class,
                ],
                // Magic sign-in link (D6): the rescue for anyone without a usable
                // password - forgotten, or filed by a password manager under the old
                // Auth0 sign-in domain. Single-use is added by SingleUseLoginLinkHandler
                // (D18), which decorates the handler this factory registers - Symfony's
                // own max_uses needs a PSR-6 pool and cannot express "issued by us".
                'login_link' => [
                    'check_route' => 'sign_in_link_check',
                    // Only a POST signs in. Mail providers fetch links before (Gmail
                    // pre-fetch) or at the moment (Outlook Safe Links "time of click")
                    // the reader clicks them; with GET sign-in that fetch consumed the
                    // single-use link and the person behind it was told it was already
                    // used (26 accounts in the 30 days before 2026-09-11, all Microsoft
                    // mailboxes). GET on the check route now renders a self-submitting
                    // form carrying the link parameters (SignInLinkCheckController);
                    // scanners fetch with bare HTTP clients and submit no forms. Second
                    // layer for any scanner that does: SingleUseLoginLinkHandler keeps
                    // a link usable for signInLinkReuseGraceSeconds after its first use.
                    'check_post_only' => true,
                    // The link dies when the address it was sent to changes, and when the
                    // password changes (a reset must not leave older links usable)
                    'signature_properties' => ['email', 'password'],
                    'lifetime' => '%signInLinkLifetimeSeconds%',
                    'success_handler' => LoginLinkSuccessHandler::class,
                    'failure_handler' => LoginLinkFailureHandler::class,
                ],
                // Always-on sliding 30-day login, no "remember me" checkbox: every
                // successful sign-in (password, social, magic link) mints a signed
                // cookie, and SignatureRememberMeHandler re-issues it with a fresh
                // 30-day window each time it is consumed - an active user is never
                // signed out, an idle one after 30 days. The handler inherits this
                // firewall's provider, so a cookie for a deleted account degrades to
                // "invalid cookie, stay anonymous" (UserNotFoundException).
                'remember_me' => [
                    // 30 days. Shared with the session cookie and the session row
                    // (config/services.php) - SlidingLoginCookiesSubscriber renews
                    // them together, so they have to be the same number.
                    'lifetime' => '%loginLifetimeSeconds%',
                    // No checkbox in the login form - being kept signed in is the
                    // default for everyone (product decision, 2026-08-01)
                    'always_remember_me' => true,
                    // Signature-based (no token table): the cookie dies when the
                    // password or the address it belongs to changes, so a password
                    // reset or email change signs every device out. Mirrors the
                    // login_link signature above. Null passwords (social-only
                    // accounts) hash as '' - supported by SignatureHasher.
                    'signature_properties' => ['email', 'password'],
                    // 'auto' = secure whenever the request is HTTPS, matching the
                    // session cookie. Hard true would silently disable remember-me on
                    // plain-HTTP dev and in the functional test client.
                    'secure' => 'auto',
                    'samesite' => 'lax',
                    'httponly' => true,
                    'path' => '/',
                ],
                // Deliberately NO firewall-level login_throttling: brute-force
                // protection lives inside LoginFormAuthenticator (5/min per email+IP +
                // per-IP limiter, config/packages/rate_limiter.php), where it counts
                // password attempts only.
                'entry_point' => LoginEntryPoint::class,
                'logout' => [
                    'path' => 'logout',
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
            // The puzzle library (summary, wishlist, unsolved puzzles, lend/borrow,
            // sell/swap) is collections:read - "read the puzzle library"; the owner's
            // visibility settings are applied inside the providers, never as 403.
            [
                'path' => '^/api/v1/players/.*/(library|wishlist|unsolved-puzzles|lend-borrow|sell-swap)',
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
            // The one corner of /admin open to community moderators as well as admins.
            // Must stay above ^/admin - the first matching rule wins.
            [
                'path' => '^/admin/puzzle-(change|merge)-requests',
                'roles' => [PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS],
            ],
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
