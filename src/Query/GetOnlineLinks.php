<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\OnlineLinkRow;
use SpeedPuzzling\Web\Results\ParticipantsSheet\ParticipantsSheetPlayer;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The personal links of an event's participants (docs/features/online-competitions/README.md §5.4) - organiser tooling
 * behind ONLINE_ROUNDS_MANAGE: every participant who is not removed, by name, waitlisted ones marked. One statement.
 *
 * Privacy like the participants sheet (GetParticipantsSheetState): a participant row is never hidden, but the linked
 * profile's identity is withheld when the viewer blocks that player (HiddenPlayers) or the profile is private to the
 * viewer (PrivateProfileAccess, the allow list included; nobody is private to themselves).
 */
readonly final class GetOnlineLinks
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
        private UrlGeneratorInterface $urlGenerator,
        private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * @return list<OnlineLinkRow>
     */
    public function ofCompetition(string $competitionId, null|string $viewerPlayerId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $waitlisted = RegistrationStatus::Waitlisted->value;

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cp.id,
    cp.name,
    cp.country,
    cp.online_token,
    cp.online_token_created_at,
    cp.registration_status IS NOT DISTINCT FROM '{$waitlisted}' AS waitlisted,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country,
    p.avatar AS player_avatar,
    {$this->privateProfileAccess->sqlIsPrivate('p')} AS player_is_private
FROM competition_participant cp
LEFT JOIN player p ON p.id = cp.player_id
WHERE cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
ORDER BY cp.name, cp.id
SQL,
            ['competitionId' => strtolower($competitionId)],
        );

        $viewerPlayerId = $viewerPlayerId !== null ? strtolower($viewerPlayerId) : null;
        $links = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: string, country: null|string, online_token: null|string, online_token_created_at: null|string, waitlisted: bool, player_id: null|string, player_name: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool} $row */
            $playerId = $row['player_id'] !== null ? strtolower($row['player_id']) : null;

            $links[] = new OnlineLinkRow(
                participantId: strtolower($row['id']),
                name: $row['name'],
                country: $row['country'],
                waitlisted: $row['waitlisted'],
                token: $row['online_token'],
                tokenCreatedAt: $row['online_token_created_at'] !== null ? new DateTimeImmutable($row['online_token_created_at']) : null,
                player: $playerId !== null ? $this->player(
                    playerId: $playerId,
                    name: $row['player_name'],
                    code: (string) $row['player_code'],
                    country: $row['player_country'],
                    avatar: $row['player_avatar'],
                    privateToViewer: $row['player_is_private'] === true && $playerId !== $viewerPlayerId,
                ) : null,
            );
        }

        return $links;
    }

    private function player(string $playerId, null|string $name, string $code, null|string $country, null|string $avatar, bool $privateToViewer): ParticipantsSheetPlayer
    {
        if ($privateToViewer || $this->hiddenPlayers->isHidden($playerId)) {
            return ParticipantsSheetPlayer::withheld($playerId);
        }

        return ParticipantsSheetPlayer::visible(
            id: $playerId,
            name: $name,
            code: $code,
            country: $country,
            avatar: $avatar !== null ? $this->imageThumbnail->thumbnailUrl($avatar, 'puzzle_small') : null,
            profileUrl: $this->urlGenerator->generate('player_profile', ['playerId' => $playerId]),
        );
    }
}
