<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Every scope the OAuth2 server can grant. The list wired into
 * config/packages/league_oauth2_server.php is built from these cases, so
 * adding a scope here is the only step needed to make it grantable.
 *
 * The role league/oauth2-server-bundle grants for a scope is
 * strtoupper('ROLE_OAUTH2_' . scope) with no punctuation normalisation - so
 * "solving-times:write" becomes ROLE_OAUTH2_SOLVING-TIMES:WRITE, hyphen kept.
 * Use role() in security expressions instead of retyping that by hand.
 */
enum OAuth2Scope: string
{
    case ProfileRead = 'profile:read';
    case EmailRead = 'email:read';
    case ResultsRead = 'results:read';
    case StatisticsRead = 'statistics:read';
    case CollectionsRead = 'collections:read';
    case SolvingTimesWrite = 'solving-times:write';
    case CollectionsWrite = 'collections:write';

    public const string ROLE_PREFIX = 'ROLE_OAUTH2_';

    /**
     * @return list<non-empty-string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * Scopes that only make sense on behalf of a signed-in user - they unlock
     * writes to /api/v1/me/*, which a client_credentials token (no user) can
     * never reach. Such scopes are stripped from client_credentials tokens.
     */
    public function requiresUserContext(): bool
    {
        return match ($this) {
            self::SolvingTimesWrite, self::CollectionsWrite => true,
            self::ProfileRead, self::EmailRead, self::ResultsRead, self::StatisticsRead, self::CollectionsRead => false,
        };
    }

    public function role(): string
    {
        return strtoupper(self::ROLE_PREFIX . $this->value);
    }
}
