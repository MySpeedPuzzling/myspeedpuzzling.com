<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Who made a public API request - the one definition used by the usage counters,
 * the stored statistics and (later) the rate limiter. The key is stable and
 * self-describing, so a counter field can be turned back into its caller:
 *
 *   pat:{tokenId}:{playerId}      personal access token
 *   oauth:{clientId}:{playerId}   OAuth2 app acting for a player
 *   client:{clientId}             OAuth2 app on its own (client_credentials)
 *
 * The client id is URL-encoded in the key, so neither ":" nor the "|" the
 * counters use as a field separator can ever appear in it.
 */
final readonly class ApiCaller
{
    private function __construct(
        public ApiCallerKind $kind,
        public null|string $personalAccessTokenId,
        public null|string $oauth2ClientIdentifier,
        public null|string $playerId,
    ) {
    }

    public static function personalAccessToken(string $tokenId, string $playerId): self
    {
        return new self(ApiCallerKind::PersonalAccessToken, $tokenId, null, $playerId);
    }

    public static function oauth2User(string $clientIdentifier, string $playerId): self
    {
        return new self(ApiCallerKind::OAuth2User, null, $clientIdentifier, $playerId);
    }

    public static function oauth2Client(string $clientIdentifier): self
    {
        return new self(ApiCallerKind::OAuth2Client, null, $clientIdentifier, null);
    }

    public function key(): string
    {
        return match ($this->kind) {
            ApiCallerKind::PersonalAccessToken => sprintf('pat:%s:%s', $this->personalAccessTokenId, $this->playerId),
            ApiCallerKind::OAuth2User => sprintf('oauth:%s:%s', rawurlencode((string) $this->oauth2ClientIdentifier), $this->playerId),
            ApiCallerKind::OAuth2Client => sprintf('client:%s', rawurlencode((string) $this->oauth2ClientIdentifier)),
        };
    }

    /**
     * @throws InvalidArgumentException when the key is not one key() produces
     */
    public static function fromKey(string $key): self
    {
        $parts = explode(':', $key);
        $kind = ApiCallerKind::tryFrom($parts[0]);

        $caller = match (true) {
            $kind === ApiCallerKind::PersonalAccessToken && count($parts) === 3
                => self::personalAccessToken($parts[1], $parts[2]),
            $kind === ApiCallerKind::OAuth2User && count($parts) === 3
                => self::oauth2User(rawurldecode($parts[1]), $parts[2]),
            $kind === ApiCallerKind::OAuth2Client && count($parts) === 2
                => self::oauth2Client(rawurldecode($parts[1])),
            default => throw new InvalidArgumentException(sprintf('Not an API caller key: "%s"', $key)),
        };

        foreach ([$caller->personalAccessTokenId, $caller->playerId] as $uuid) {
            if ($uuid !== null && Uuid::isValid($uuid) === false) {
                throw new InvalidArgumentException(sprintf('Not an API caller key: "%s"', $key));
            }
        }

        if ($caller->oauth2ClientIdentifier === '') {
            throw new InvalidArgumentException(sprintf('Not an API caller key: "%s"', $key));
        }

        return $caller;
    }
}
