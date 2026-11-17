<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * How an organiser's file names a MySpeedPuzzling player (docs/features/competitions-management/participant-linking.md
 * §7.3, D7): the player's id, their `#code` (with or without the `#`) or a link to their profile. Resolved to a player
 * by SiteSnapshotReader - this only reads the cell.
 *
 * - a UUID → the id
 * - `#abc123` / `abc123` → the code: the characters and length a player may choose (PlayerCodeFormData,
 *   `[a-zA-Z0-9]{3,8}`), compared lower-cased (codes are stored lower-cased; a few old ones are not)
 * - a URL or a path whose last segment is a UUID → the id: the profile page of any language
 *   (`/en/player-profile/<id>`, `/profil-hrace/<id>` ...) and any other page ending in the id. The profile route takes
 *   ids only, so a URL ending in anything else is not a reference
 */
readonly final class PlayerReference
{
    private const string CODE_PATTERN = '/^#?([a-z0-9]{3,8})$/i';

    private function __construct(
        /** Lower-cased player id, or null for a code */
        public null|string $playerId,
        /** Lower-cased code without the `#`, or null for an id */
        public null|string $code,
    ) {
    }

    public static function parse(string $cell): null|self
    {
        $value = trim($cell);

        if ($value === '') {
            return null;
        }

        if (Uuid::isValid($value)) {
            return new self(strtolower($value), null);
        }

        if (str_contains($value, '/')) {
            $path = parse_url($value, PHP_URL_PATH);

            // "myspeedpuzzling.com/en/player-profile/<id>" without a scheme reads as one path - fine, the last segment counts
            if (!is_string($path)) {
                return null;
            }

            $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
            $last = $segments === [] ? '' : rawurldecode($segments[count($segments) - 1]);

            return Uuid::isValid($last) ? new self(strtolower($last), null) : null;
        }

        if (preg_match(self::CODE_PATTERN, $value, $matches) === 1) {
            return new self(null, strtolower($matches[1]));
        }

        return null;
    }

    /**
     * One string per player a reference can name - the key SiteSnapshot resolves it by.
     */
    public function key(): string
    {
        return $this->playerId !== null ? 'id:' . $this->playerId : 'code:' . $this->code;
    }
}
