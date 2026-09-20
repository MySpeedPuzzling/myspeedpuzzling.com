<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Normalizer;

/**
 * Who a pair/team consists of, independent of the order people were entered in. The key is the team's
 * identity (puzzling_team.composition_key) - this class is its only implementation, the historical
 * backfill goes through it as well.
 */
readonly final class TeamComposition
{
    private const string GUEST_PREFIX = 'g:';

    /**
     * @param non-empty-list<TeamCompositionMember> $members Sorted by member key
     */
    private function __construct(
        public string $key,
        public array $members,
    ) {
    }

    public static function fromGroup(PuzzlersGroup $group): self
    {
        $members = [];
        $position = 0;

        foreach ($group->puzzlers as $puzzler) {
            if ($puzzler->playerId !== null) {
                $memberKey = strtolower($puzzler->playerId);

                // The same account listed twice is one person
                if (isset($members[$memberKey])) {
                    continue;
                }

                $members[$memberKey] = new TeamCompositionMember($memberKey, $puzzler->playerId, null, $position++);
                continue;
            }

            $guestName = self::cleanGuestName($puzzler->playerName ?? '');
            $baseKey = self::GUEST_PREFIX . self::normaliseGuestName($guestName);
            $memberKey = $baseKey;

            // Two guests may share a name ("Jana" and "Jana") - they are still two people
            for ($i = 2; isset($members[$memberKey]); $i++) {
                $memberKey = $baseKey . '#' . $i;
            }

            $members[$memberKey] = new TeamCompositionMember($memberKey, null, $guestName, $position++);
        }

        ksort($members, SORT_STRING);

        /** @var non-empty-list<TeamCompositionMember> $sortedMembers */
        $sortedMembers = array_values($members);

        return new self(
            key: sha1(implode('|', array_keys($members))),
            members: $sortedMembers,
        );
    }

    public function size(): int
    {
        return count($this->members);
    }

    public static function guestMemberKey(string $guestName): string
    {
        return self::GUEST_PREFIX . self::normaliseGuestName($guestName);
    }

    /**
     * @param list<string> $memberKeys
     */
    public static function keyFromMemberKeys(array $memberKeys): string
    {
        sort($memberKeys, SORT_STRING);

        return sha1(implode('|', $memberKeys));
    }

    private static function cleanGuestName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * "Žofie ", "zofie" and "ŽOFIE" are one guest.
     */
    private static function normaliseGuestName(string $name): string
    {
        $name = self::cleanGuestName($name);
        $decomposed = Normalizer::normalize($name, Normalizer::FORM_KD);

        if ($decomposed !== false) {
            $name = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        }

        return mb_strtolower($name);
    }
}
