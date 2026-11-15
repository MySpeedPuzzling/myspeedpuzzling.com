<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * One person as an import's file writes them (docs/features/data-import/people.md): a name - or a player's code
 * (`#abc12`, the code format of PlayerCodeFormData), a player's id, or the address of a player's profile.
 *
 * `key` is what the import's people are told apart by: a name folded exactly like a guest of a pair/team is
 * (TeamComposition - "Žofie ", "zofie" and "ŽOFIE" are one person), a code in lower case, a player's id.
 */
readonly final class ImportPersonText
{
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private function __construct(
        public string $key,
        /** The name as written, cleaned - what a guest is called. For a code: the code without its sign */
        public string $name,
        /** The player's code the text is, without the sign - null: not written as a code */
        public null|string $code,
        /** The player's id the text is (bare, or in a profile's address) - null: none */
        public null|string $playerId,
    ) {
    }

    public static function of(string $text): null|self
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^#\s*([a-zA-Z0-9]{3,8})$/', $text, $match) === 1) {
            return new self('#' . strtolower($match[1]), $match[1], $match[1], null);
        }

        // A bare id, or the address of a profile: the id is the address's last part
        if (preg_match('~^(?:https?://\S+/)?(' . self::UUID . ')/?$~', $text, $match) === 1) {
            // Never the address itself: when the player is not resolved the name is what a guest is called, on a
            // public result - a neutral one, apart for every id
            return new self('id:' . strtolower($match[1]), 'Player ' . substr(strtolower($match[1]), 0, 8), null, strtolower($match[1]));
        }

        $name = trim($text, "# \t\n\r\0\x0B");

        if ($name === '') {
            return null;
        }

        return new self(mb_substr(TeamComposition::guestMemberKey($name), 0, 160), $name, null, null);
    }

    public function isWrittenAsPlayer(): bool
    {
        return $this->code !== null || $this->playerId !== null;
    }

    /**
     * The key of a plain name - what a player's own name is compared by.
     */
    public static function nameKey(string $name): string
    {
        return mb_substr(TeamComposition::guestMemberKey($name), 0, 160);
    }
}
