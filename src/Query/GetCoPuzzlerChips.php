<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * How the co-puzzlers a form already holds ("#CODE" or a guest name - when editing a time, or when a
 * submitted form comes back with an error) look as chips of the picker. Never runs for a solo form.
 */
readonly final class GetCoPuzzlerChips
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * @param array<string> $groupPlayers
     * @return list<array{key: string, value: string, label: string, code: null|string, guest: bool, country: null|string, avatar: null|string}>
     */
    public function forGroupPlayers(array $groupPlayers): array
    {
        $codes = [];

        foreach ($groupPlayers as $groupPlayer) {
            if (str_starts_with($groupPlayer, '#')) {
                $codes[] = strtolower(trim($groupPlayer, "# \t\n\r\0"));
            }
        }

        $players = [];

        if ($codes !== []) {
            // The members of a time the viewer may edit: they entered these codes themselves, or puzzled
            // with these people - no blocklist filter, a private player shows as their code
            $isPrivate = $this->privateProfileAccess->sqlIsPrivate('player');

            $rows = $this->database->fetchAllAssociative(
                <<<SQL
SELECT
    player.id AS player_id,
    player.code AS player_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.name END AS player_name,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.country END AS player_country,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.avatar END AS player_avatar
FROM player
WHERE LOWER(player.code) IN (:codes)
SQL,
                ['codes' => $codes],
                ['codes' => ArrayParameterType::STRING],
            );

            foreach ($rows as $row) {
                /** @var array{player_id: string, player_code: string, player_name: null|string, player_country: null|string, player_avatar: null|string} $row */
                $players[strtolower($row['player_code'])] = $row;
            }
        }

        $chips = [];

        foreach ($groupPlayers as $groupPlayer) {
            $groupPlayer = trim($groupPlayer);

            if ($groupPlayer === '') {
                continue;
            }

            $code = str_starts_with($groupPlayer, '#') ? strtolower(trim($groupPlayer, "# \t\n\r\0")) : null;
            $player = $code !== null ? ($players[$code] ?? null) : null;

            if ($player !== null) {
                $chips[] = [
                    'key' => $player['player_id'],
                    'value' => '#' . strtoupper($player['player_code']),
                    'label' => $player['player_name'] ?? '#' . strtoupper($player['player_code']),
                    'code' => strtoupper($player['player_code']),
                    'guest' => false,
                    'country' => $player['player_country'],
                    'avatar' => $player['player_avatar'] !== null ? $this->imageThumbnail->thumbnailUrl($player['player_avatar'], 'puzzle_small') : null,
                ];

                continue;
            }

            // Not a known code: exactly what the handler will store - a guest of that name
            $name = trim($groupPlayer, "# \t\n\r\0");

            $chips[] = [
                'key' => TeamComposition::guestMemberKey($name),
                'value' => $groupPlayer,
                'label' => $name,
                'code' => null,
                'guest' => true,
                'country' => null,
                'avatar' => null,
            ];
        }

        return $chips;
    }

    /**
     * @return null|array{key: string, value: string, label: string, code: null|string, guest: bool, country: null|string, avatar: null|string}
     */
    public function forPlayerId(string $playerId): null|array
    {
        $code = $this->database->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => $playerId]);

        if (is_string($code) === false) {
            return null;
        }

        return $this->forGroupPlayers(['#' . $code])[0] ?? null;
    }

    /**
     * The form values ("#CODE" / guest name) that select this pair/team - for the "Add time" deep link.
     * Empty unless the viewer is a member: nobody gets somebody else's team filled in.
     *
     * @return list<string>
     */
    public function groupPlayersOfTeam(string $teamId, string $viewerPlayerId): array
    {
        if (Uuid::isValid($teamId) === false) {
            return [];
        }

        /** @var list<array{player_id: null|string, guest_name: null|string, code: null|string}> $members */
        $members = $this->database->fetchAllAssociative(
            <<<SQL
SELECT member.player_id, member.guest_name, player.code
FROM puzzling_team_member member
LEFT JOIN player ON player.id = member.player_id
WHERE member.team_id = :teamId
ORDER BY member.position
SQL,
            ['teamId' => $teamId],
        );

        if (in_array($viewerPlayerId, array_column($members, 'player_id'), true) === false) {
            return [];
        }

        $groupPlayers = [];

        foreach ($members as $member) {
            if ($member['player_id'] === $viewerPlayerId) {
                continue;
            }

            $groupPlayers[] = $member['code'] !== null ? '#' . strtoupper($member['code']) : (string) $member['guest_name'];
        }

        return $groupPlayers;
    }
}
